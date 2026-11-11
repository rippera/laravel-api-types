<?php

namespace ApiTypes\Collectors;

use ApiTypes\Attributes\ApiResponse;
use ApiTypes\Data\ResponseDefinition;
use ApiTypes\Support\PhpFile;
use ApiTypes\Support\ResourceTypeBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Figures out what a controller action returns, in this order:
 *   1. #[ApiResponse(...)] attribute
 *   2. native return type (UserResource, UserCollection, User)
 *   3. the method body: return UserResource::collection(User::paginate()), new UserResource($user), ...
 */
class ResponseResolver
{
    public function resolve(string $controller, string $method): ?ResponseDefinition
    {
        $reflection = new ReflectionMethod($controller, $method);

        foreach ($reflection->getAttributes(ApiResponse::class) as $attribute) {
            return $this->fromAttribute($attribute->newInstance());
        }

        $body = $this->methodNode($reflection);
        $pagination = $body ? $this->pagination($body) : null;
        $returnType = $reflection->getReturnType();

        if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()
            && ($definition = $this->fromClass($returnType->getName(), $pagination, allowModel: true))) {
            return $definition;
        }

        if ($body === null) {
            return null;
        }

        return $this->fromBody($body, $pagination)
            ?? $this->fromReturnedParameter($reflection, $body)
            ?? $this->fromNoContent($body);
    }

    /** public function show(User $user) { return $user; } */
    private function fromReturnedParameter(ReflectionMethod $reflection, ClassMethod $method): ?ResponseDefinition
    {
        $returns = (new NodeFinder)->findInstanceOf($method->stmts ?? [], Return_::class);

        foreach ($returns as $return) {
            if (! $return->expr instanceof Variable || ! is_string($return->expr->name)) {
                continue;
            }

            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($parameter->getName() === $return->expr->name && $type instanceof ReflectionNamedType
                    && ! $type->isBuiltin() && is_subclass_of($type->getName(), Model::class)) {
                    return new ResponseDefinition(ResponseDefinition::MODEL, $type->getName());
                }
            }
        }

        return null;
    }

    /** return response()->noContent(); */
    private function fromNoContent(ClassMethod $method): ?ResponseDefinition
    {
        $call = (new NodeFinder)->findFirst($method->stmts ?? [], fn (Node $node) => $node instanceof MethodCall
            && $node->name instanceof Identifier && strtolower($node->name->toString()) === 'nocontent');

        return $call ? new ResponseDefinition(ResponseDefinition::RAW, 'null') : null;
    }

    private function fromAttribute(ApiResponse $attribute): ResponseDefinition
    {
        $pagination = $attribute->paginated === true ? 'length' : ($attribute->paginated ?: null);
        $collection = $attribute->collection || $pagination !== null;
        $class = ltrim($attribute->type, '\\');

        if (class_exists($class)) {
            if (is_subclass_of($class, ResourceCollection::class) && ($collects = ResourceTypeBuilder::collects($class))) {
                return new ResponseDefinition(ResponseDefinition::RESOURCE, $collects, true, $pagination);
            }

            if (is_subclass_of($class, JsonResource::class)) {
                return new ResponseDefinition(ResponseDefinition::RESOURCE, $class, $collection, $pagination);
            }

            if (is_subclass_of($class, Model::class)) {
                return new ResponseDefinition(ResponseDefinition::MODEL, $class, $collection, $pagination);
            }
        }

        return new ResponseDefinition(ResponseDefinition::RAW, $attribute->type, $collection, $pagination);
    }

    private function fromClass(string $class, ?string $pagination, bool $allowModel = false): ?ResponseDefinition
    {
        if (! class_exists($class)) {
            return null;
        }

        if (is_subclass_of($class, ResourceCollection::class)) {
            // AnonymousResourceCollection (from ::collection()) doesn't tell us the item type.
            $collects = is_a($class, AnonymousResourceCollection::class, true) ? null : ResourceTypeBuilder::collects($class);

            return $collects
                ? new ResponseDefinition(ResponseDefinition::RESOURCE, $collects, true, $pagination)
                : null;
        }

        if (is_subclass_of($class, JsonResource::class)) {
            return new ResponseDefinition(ResponseDefinition::RESOURCE, $class);
        }

        if ($allowModel && is_subclass_of($class, Model::class)) {
            return new ResponseDefinition(ResponseDefinition::MODEL, $class);
        }

        return null;
    }

    private function fromBody(ClassMethod $method, ?string $pagination): ?ResponseDefinition
    {
        $finder = new NodeFinder;
        $returns = array_filter(array_map(
            fn (Return_ $return) => $return->expr,
            $finder->findInstanceOf($method->stmts ?? [], Return_::class),
        ));

        foreach ($returns ?: [$method] as $scope) {
            $calls = $finder->find($scope, fn (Node $node) => ($node instanceof StaticCall || $node instanceof New_)
                && $node->class instanceof Name);

            foreach ($calls as $call) {
                $class = $call->class->toString();

                if (! class_exists($class) || ! is_subclass_of($class, JsonResource::class)) {
                    continue;
                }

                $name = $call instanceof StaticCall && $call->name instanceof Identifier
                    ? strtolower($call->name->toString())
                    : 'new';

                if ($name === 'collection' && ! is_subclass_of($class, ResourceCollection::class)) {
                    return new ResponseDefinition(ResponseDefinition::RESOURCE, $class, true, $pagination);
                }

                if (in_array($name, ['make', 'new'], true) && ($definition = $this->fromClass($class, $pagination))) {
                    return $definition;
                }
            }
        }

        return null;
    }

    /** @return 'length'|'simple'|'cursor'|null */
    private function pagination(ClassMethod $method): ?string
    {
        $calls = (new NodeFinder)->find($method->stmts ?? [], fn (Node $node) => ($node instanceof MethodCall || $node instanceof StaticCall)
            && $node->name instanceof Identifier);

        foreach ($calls as $call) {
            $kind = match (strtolower($call->name->toString())) {
                'paginate' => 'length',
                'simplepaginate' => 'simple',
                'cursorpaginate' => 'cursor',
                default => null,
            };

            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    private function methodNode(ReflectionMethod $method): ?ClassMethod
    {
        $class = $method->getDeclaringClass()->getName();

        return PhpFile::forClass($class)?->findClass($class)?->getMethod($method->getName());
    }
}
