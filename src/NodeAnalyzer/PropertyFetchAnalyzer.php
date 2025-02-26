<?php

declare(strict_types=1);

namespace Rector\Core\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Type\ObjectType;
use Rector\Core\Enum\ObjectReference;
use Rector\Core\PhpParser\AstResolver;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Core\ValueObject\MethodName;
use Rector\NodeNameResolver\NodeNameResolver;
use Symplify\Astral\NodeTraverser\SimpleCallableNodeTraverser;

final class PropertyFetchAnalyzer
{
    /**
     * @var string
     */
    private const THIS = 'this';

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly AstResolver $astResolver,
        private readonly SimpleCallableNodeTraverser $simpleCallableNodeTraverser
    ) {
    }

    public function isLocalPropertyFetch(Node $node): bool
    {
        if ($node instanceof PropertyFetch) {
            if (! $node->var instanceof Variable) {
                return false;
            }

            return $this->nodeNameResolver->isName($node->var, self::THIS);
        }

        if ($node instanceof StaticPropertyFetch) {
            if (! $node->class instanceof Name) {
                return false;
            }

            return $this->nodeNameResolver->isNames($node->class, [
                ObjectReference::SELF,
                ObjectReference::STATIC,
            ]);
        }

        return false;
    }

    public function isLocalPropertyFetchName(Node $node, string $desiredPropertyName): bool
    {
        if (! $this->isLocalPropertyFetch($node)) {
            return false;
        }

        /** @var PropertyFetch|StaticPropertyFetch $node */
        return $this->nodeNameResolver->isName($node->name, $desiredPropertyName);
    }

    public function countLocalPropertyFetchName(ClassLike $classLike, string $propertyName): int
    {
        $total = 0;

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($classLike->stmts, function (Node $subNode) use (
            $classLike,
            $propertyName,
            &$total
        ): ?Node {
            if (! $this->isLocalPropertyFetchName($subNode, $propertyName)) {
                return null;
            }

            $parentClassLike = $this->betterNodeFinder->findParentType($subNode, ClassLike::class);

            // property fetch in Trait cannot get parent ClassLike
            if (! $parentClassLike instanceof ClassLike) {
                ++$total;
            }

            if ($parentClassLike === $classLike) {
                ++$total;
            }

            return $subNode;
        });

        return $total;
    }

    public function containsLocalPropertyFetchName(Node $node, string $propertyName): bool
    {
        $classLike = $node instanceof ClassLike
            ? $node
            : $this->betterNodeFinder->findParentType($node, ClassLike::class);

        return (bool) $this->betterNodeFinder->findFirst(
            $node,
            function (Node $node) use ($classLike, $propertyName): bool {
                if (! $this->isLocalPropertyFetchName($node, $propertyName)) {
                    return false;
                }

                $parentClassLike = $this->betterNodeFinder->findParentType($node, ClassLike::class);

                // property fetch in Trait cannot get parent ClassLike
                if (! $parentClassLike instanceof ClassLike) {
                    return true;
                }

                return $parentClassLike === $classLike;
            }
        );
    }

    public function isPropertyToSelf(PropertyFetch | StaticPropertyFetch $expr): bool
    {
        if ($expr instanceof PropertyFetch && ! $this->nodeNameResolver->isName($expr->var, self::THIS)) {
            return false;
        }

        if ($expr instanceof StaticPropertyFetch && ! $this->nodeNameResolver->isName(
            $expr->class,
            ObjectReference::SELF
        )) {
            return false;
        }

        $class = $this->betterNodeFinder->findParentType($expr, Class_::class);
        if (! $class instanceof Class_) {
            return false;
        }

        foreach ($class->getProperties() as $property) {
            if (! $this->nodeNameResolver->areNamesEqual($property->props[0], $expr)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function isPropertyFetch(Node $node): bool
    {
        if ($node instanceof PropertyFetch) {
            return true;
        }

        return $node instanceof StaticPropertyFetch;
    }

    /**
     * Matches:
     * "$this->someValue = $<variableName>;"
     */
    public function isVariableAssignToThisPropertyFetch(Node $node, string $variableName): bool
    {
        if (! $node instanceof Assign) {
            return false;
        }

        if (! $node->expr instanceof Variable) {
            return false;
        }

        if (! $this->nodeNameResolver->isName($node->expr, $variableName)) {
            return false;
        }

        return $this->isLocalPropertyFetch($node->var);
    }

    public function isFilledViaMethodCallInConstructStmts(ClassLike $classLike, string $propertyName): bool
    {
        $classMethod = $classLike->getMethod(MethodName::CONSTRUCT);
        if (! $classMethod instanceof ClassMethod) {
            return false;
        }

        $className = (string) $this->nodeNameResolver->getName($classLike);
        $stmts = (array) $classMethod->stmts;

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            if (! $stmt->expr instanceof MethodCall && ! $stmt->expr instanceof StaticCall) {
                continue;
            }

            $callerClassMethod = $this->astResolver->resolveClassMethodFromCall($stmt->expr);
            if (! $callerClassMethod instanceof ClassMethod) {
                continue;
            }

            $callerClass = $this->betterNodeFinder->findParentType($callerClassMethod, Class_::class);
            if (! $callerClass instanceof Class_) {
                continue;
            }

            $callerClassName = (string) $this->nodeNameResolver->getName($callerClass);
            $isFound = $this->isPropertyAssignFoundInClassMethod(
                $classLike,
                $className,
                $callerClassName,
                $callerClassMethod,
                $propertyName
            );
            if ($isFound) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $propertyNames
     */
    public function isLocalPropertyOfNames(Node $node, array $propertyNames): bool
    {
        if (! $this->isLocalPropertyFetch($node)) {
            return false;
        }

        /** @var PropertyFetch $node */
        return $this->nodeNameResolver->isNames($node->name, $propertyNames);
    }

    private function isPropertyAssignFoundInClassMethod(
        ClassLike $classLike,
        string $className,
        string $callerClassName,
        ClassMethod $classMethod,
        string $propertyName
    ): bool {
        if ($className !== $callerClassName && ! $classLike instanceof Trait_) {
            $objectType = new ObjectType($className);
            $callerObjectType = new ObjectType($callerClassName);

            if (! $callerObjectType->isSuperTypeOf($objectType)->yes()) {
                return false;
            }
        }

        foreach ((array) $classMethod->stmts as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            if (! $stmt->expr instanceof Assign) {
                continue;
            }

            if ($this->isLocalPropertyFetchName($stmt->expr->var, $propertyName)) {
                return true;
            }
        }

        return false;
    }
}
