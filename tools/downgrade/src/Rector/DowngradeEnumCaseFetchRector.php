<?php

declare(strict_types=1);

namespace Mcp\Downgrade\Rector;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Reflection\ReflectionProvider;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Points every enum-case reference at the PHP 7.4 accessor that replaces it.
 *
 * Runs against the still-enum sources, because deciding whether `Foo::Bar` is a
 * case or an ordinary constant needs reflection only the original code carries.
 * A constant *declaration* holding a case is scalarised instead of rewritten —
 * a PHP 7.4 constant expression cannot hold an object.
 */
final class DowngradeEnumCaseFetchRector extends AbstractRector
{
    private ReflectionProvider $reflectionProvider;

    /**
     * Fetches sitting inside a constant declaration, which must stay constant
     * expressions. Filled when the declaration is entered, always ahead of its
     * own children.
     */
    private \SplObjectStorage $withinConstantExpression;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
        $this->withinConstantExpression = new \SplObjectStorage();
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Rewrite enum case constant fetches to the PHP 7.4 static accessor', [new CodeSample(
            'Role::User',
            'Role::User()'
        )]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassConst::class, ClassConstFetch::class];
    }

    /**
     * @param ClassConst|ClassConstFetch $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof ClassConst) {
            return $this->scalariseDeclaration($node);
        }

        return $this->rewriteFetch($node);
    }

    /**
     * A declaration inside an enum keeps its case fetch: the enum rule turns the
     * whole body into constants and accessors in one go.
     */
    private function scalariseDeclaration(ClassConst $classConst): ?ClassConst
    {
        $nodeFinder = new NodeFinder();
        foreach ($classConst->consts as $const) {
            foreach ($nodeFinder->findInstanceOf($const->value, ClassConstFetch::class) as $nested) {
                $this->withinConstantExpression->attach($nested);
            }
        }

        $scope = $classConst->getAttribute(AttributeKey::SCOPE);
        if ($scope instanceof Scope) {
            $classReflection = $scope->getClassReflection();
            if ($classReflection instanceof ClassReflection && $classReflection->isEnum()) {
                return null;
            }
        }

        $hasChanged = false;
        foreach ($classConst->consts as $const) {
            if (!$const->value instanceof ClassConstFetch) {
                continue;
            }

            $case = $this->resolveCase($const->value);
            if (null === $case) {
                continue;
            }

            $const->value = $this->createScalar($case[0], $case[1]);
            $hasChanged = true;
        }

        return $hasChanged ? $classConst : null;
    }

    private function rewriteFetch(ClassConstFetch $classConstFetch): ?StaticCall
    {
        if (!$classConstFetch->name instanceof Identifier || $this->withinConstantExpression->contains($classConstFetch)) {
            return null;
        }

        $case = $this->resolveCase($classConstFetch);
        if (null === $case) {
            return null;
        }

        $class = $classConstFetch->class;
        \assert($class instanceof Name);

        if (\in_array($class->toString(), ['self', 'static', 'parent'], true) || $this->isEnumClass($class->toString())) {
            // Either a case, or an alias constant on the enum itself — both get
            // an accessor of the same name.
            return new StaticCall($class, new Identifier($classConstFetch->name->toString()), []);
        }

        return new StaticCall(new FullyQualified($case[0]->getName()), new Identifier($case[1]), []);
    }

    private function isEnumClass(string $className): bool
    {
        return $this->reflectionProvider->hasClass($className) && $this->reflectionProvider->getClass($className)->isEnum();
    }

    /**
     * @param string|null $selfClass class `self` resolves to when the fetch came
     *                               out of reflection and carries no scope
     *
     * @return array{0: ClassReflection, 1: string}|null the enum and the case name behind this fetch
     */
    private function resolveCase(ClassConstFetch $classConstFetch, ?string $selfClass = null): ?array
    {
        if (!$classConstFetch->name instanceof Identifier) {
            return null;
        }

        $className = $this->resolveClassName($classConstFetch) ?? $selfClass;
        if (null === $className || !$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($className);
        $constantName = $classConstFetch->name->toString();

        if ($classReflection->isEnum() && $classReflection->hasEnumCase($constantName)) {
            return [$classReflection, $constantName];
        }

        if (!$classReflection->hasConstant($constantName)) {
            return null;
        }

        $valueExpr = $classReflection->getConstant($constantName)->getValueExpr();
        if (!$valueExpr instanceof ClassConstFetch) {
            return null;
        }

        return $this->resolveCase($valueExpr, $classReflection->getName());
    }

    private function resolveClassName(ClassConstFetch $classConstFetch): ?string
    {
        $class = $classConstFetch->class;
        if (!$class instanceof Name) {
            return null;
        }

        $name = $class->toString();
        if (!\in_array($name, ['self', 'static', 'parent'], true)) {
            return $name;
        }

        $scope = $classConstFetch->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope) {
            return null;
        }

        $classReflection = $scope->getClassReflection();

        return $classReflection instanceof ClassReflection ? $classReflection->getName() : null;
    }

    private function createScalar(ClassReflection $enumReflection, string $caseName): Expr
    {
        $backingValueType = $enumReflection->getEnumCase($caseName)->getBackingValueType();

        if ($backingValueType instanceof ConstantStringType) {
            return new String_($backingValueType->getValue());
        }

        if ($backingValueType instanceof ConstantIntegerType) {
            return new Int_($backingValueType->getValue());
        }

        throw new \RuntimeException(\sprintf('Enum case "%s::%s" has no constant backing value.', $enumReflection->getName(), $caseName));
    }
}
