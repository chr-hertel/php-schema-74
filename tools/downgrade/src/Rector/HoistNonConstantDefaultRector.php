<?php

declare(strict_types=1);

namespace Mcp\Downgrade\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Moves a call-valued default out of the signature and into the body.
 *
 * Enum cases reach PHP 7.4 as static accessors, and a 7.4 default value has to
 * be a constant expression. The parameter becomes nullable and the accessor runs
 * on entry instead — the same shape Rector uses for `new` in initializers.
 */
final class HoistNonConstantDefaultRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Hoist a call-valued parameter default into the function body', [new CodeSample(
            "public function __construct(Mode \$mode = Mode::Auto())\n{\n}",
            "public function __construct(?Mode \$mode = null)\n{\n    \$mode = \$mode ?? Mode::Auto();\n}"
        )]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class, Property::class];
    }

    /**
     * @param ClassMethod|Property $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Property) {
            return $this->dropPropertyDefault($node);
        }

        return $this->hoistParams($node);
    }

    /**
     * The promoted property this default came from is assigned in the
     * constructor anyway, so dropping it here loses nothing.
     */
    private function dropPropertyDefault(Property $property): ?Property
    {
        $hasChanged = false;
        foreach ($property->props as $propertyItem) {
            if ($propertyItem->default instanceof StaticCall) {
                $propertyItem->default = null;
                $hasChanged = true;
            }
        }

        return $hasChanged ? $property : null;
    }

    private function hoistParams(ClassMethod $classMethod): ?ClassMethod
    {
        if (null === $classMethod->stmts) {
            return null;
        }

        $prepend = [];
        foreach ($classMethod->params as $param) {
            if (!$param->default instanceof StaticCall || !$param->var instanceof Variable || !\is_string($param->var->name)) {
                continue;
            }

            $variable = new Variable($param->var->name);
            $prepend[] = new Expression(new Assign($variable, new Coalesce($variable, $param->default)));

            $param->default = new ConstFetch(new Name('null'));
            if ($param->type instanceof Identifier || $param->type instanceof Name) {
                $param->type = new NullableType($param->type);
            }
        }

        if ([] === $prepend) {
            return null;
        }

        $classMethod->stmts = array_merge($prepend, $classMethod->stmts);

        return $classMethod;
    }
}
