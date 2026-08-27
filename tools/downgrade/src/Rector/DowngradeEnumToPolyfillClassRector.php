<?php

declare(strict_types=1);

namespace Mcp\Downgrade\Rector;

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Return_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites a backed enum into a PHP 7.4 class extending the BackedEnum polyfill.
 *
 * Each case becomes a scalar constant — the only form a 7.4 constant expression
 * takes — plus a same-named static accessor returning the memoised instance, so
 * `$case->value`, `::from()`, `::cases()` and identity comparison all survive.
 */
final class DowngradeEnumToPolyfillClassRector extends AbstractRector
{
    /**
     * @var string
     */
    public const POLYFILL_CLASS = 'Mcp\Schema\Enum\BackedEnum';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Downgrade a backed enum to a PHP 7.4 class backed by the BackedEnum polyfill', [new CodeSample(
            <<<'CODE_SAMPLE'
enum Role: string
{
    case User = 'user';
}
CODE_SAMPLE
            ,
            <<<'CODE_SAMPLE'
final class Role extends \Mcp\Schema\Enum\BackedEnum
{
    public const User = 'user';

    public static function User(): self
    {
        return self::case('User');
    }

    protected static function definition(): array
    {
        return ['User' => self::User];
    }
}
CODE_SAMPLE
        )]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Enum_::class];
    }

    /**
     * @param Enum_ $node
     */
    public function refactor(Node $node): ?Class_
    {
        if (null === $node->scalarType) {
            throw new \RuntimeException(\sprintf('Pure enum "%s" has no backing value and cannot be downgraded.', (string) $node->namespacedName));
        }

        $constants = [];
        $accessors = [];
        $definition = [];
        $caseValues = [];
        $rest = [];

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof EnumCase) {
                $rest[] = $stmt;
                continue;
            }

            $caseName = $stmt->name->toString();
            if (!$stmt->expr instanceof Expr) {
                throw new \RuntimeException(\sprintf('Enum case "%s::%s" has no backing value.', (string) $node->namespacedName, $caseName));
            }

            $caseValues[$caseName] = $stmt->expr;
            $constants[] = $this->createConstant($caseName, $stmt->expr, $stmt);
            $accessors[] = $this->createAccessor($caseName);
            $definition[] = new ArrayItem($this->copyValue($stmt->expr), new String_($caseName));
        }

        // A constant whose value is a case cannot stay a constant: it becomes an
        // accessor of the same name, and a scalar constant carrying the raw value.
        $rest = $this->rewriteCaseValuedConstants($rest, $caseValues, $constants, $accessors);

        $class = new Class_($node->name, [
            'flags' => Class_::MODIFIER_FINAL,
            'extends' => new FullyQualified(self::POLYFILL_CLASS),
            'stmts' => array_merge($constants, $accessors, [$this->createDefinitionMethod($definition)], $rest),
        ], $this->carryOver($node));

        $class->namespacedName = $node->namespacedName;

        return $class;
    }

    /**
     * @param ClassConst[]         $rest
     * @param array<string, Expr>   $caseValues
     * @param ClassConst[]          $constants
     * @param ClassMethod[]         $accessors
     *
     * @return Node\Stmt[]
     */
    private function rewriteCaseValuedConstants(array $rest, array $caseValues, array &$constants, array &$accessors): array
    {
        $kept = [];

        foreach ($rest as $stmt) {
            if (!$stmt instanceof ClassConst) {
                $kept[] = $stmt;
                continue;
            }

            $plain = [];
            foreach ($stmt->consts as $const) {
                $target = $this->resolveSelfCaseName($const->value);
                if (null === $target || !isset($caseValues[$target])) {
                    $plain[] = $const;
                    continue;
                }

                $alias = new ClassConst([new Const_($const->name, $this->copyValue($caseValues[$target]))], $stmt->flags, $this->carryOver($stmt));
                $constants[] = $alias;
                $accessors[] = $this->createAccessor($const->name->toString(), $target);
            }

            if ([] !== $plain) {
                $stmt->consts = $plain;
                $kept[] = $stmt;
            }
        }

        return $kept;
    }

    /**
     * A backing value is reused in three places; each needs its own node, since
     * the printer tracks original positions per node instance.
     */
    private function copyValue(Expr $expr): Expr
    {
        if ($expr instanceof String_) {
            return new String_($expr->value);
        }

        if ($expr instanceof Int_) {
            return new Int_($expr->value);
        }

        throw new \RuntimeException(\sprintf('Enum backing value of type "%s" is not supported.', $expr->getType()));
    }

    private function resolveSelfCaseName(Expr $expr): ?string
    {
        if (!$expr instanceof ClassConstFetch || !$expr->class instanceof Name || !$expr->name instanceof Identifier) {
            return null;
        }

        return 'self' === $expr->class->toString() ? $expr->name->toString() : null;
    }

    /**
     * Line numbers and docblocks only: carrying `origNode` over would make the
     * format-preserving printer re-print the replacement as its original kind.
     *
     * @return array<string, mixed>
     */
    private function carryOver(Node $node): array
    {
        $attributes = ['startLine' => $node->getStartLine(), 'endLine' => $node->getEndLine()];

        if ([] !== $node->getComments()) {
            $attributes['comments'] = $node->getComments();
        }

        return $attributes;
    }

    private function createConstant(string $name, Expr $value, EnumCase $enumCase): ClassConst
    {
        return new ClassConst([new Const_(new Identifier($name), $value)], Class_::MODIFIER_PUBLIC, $this->carryOver($enumCase));
    }

    private function createAccessor(string $name, ?string $caseName = null): ClassMethod
    {
        return new ClassMethod(new Identifier($name), [
            'flags' => Class_::MODIFIER_PUBLIC | Class_::MODIFIER_STATIC,
            'returnType' => new Identifier('self'),
            'stmts' => [new Return_(new StaticCall(new Name('self'), new Identifier('case'), [new Node\Arg(new String_($caseName ?? $name))]))],
        ]);
    }

    /**
     * @param ArrayItem[] $items
     */
    private function createDefinitionMethod(array $items): ClassMethod
    {
        $method = new ClassMethod(new Identifier('definition'), [
            'flags' => Class_::MODIFIER_PROTECTED | Class_::MODIFIER_STATIC,
            'returnType' => new Identifier('array'),
            'stmts' => [new Return_(new Array_($items, ['kind' => Array_::KIND_SHORT]))],
        ]);

        $method->setDocComment(new \PhpParser\Comment\Doc("/**\n * @return array<string, string|int>\n */"));

        return $method;
    }
}
