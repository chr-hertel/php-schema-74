<?php

declare(strict_types=1);

namespace Mcp\Downgrade\Rector;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Turns the PHPUnit 10 metadata attributes back into the annotations PHPUnit 9 reads.
 *
 * Anything unmapped aborts the run: the stock downgrade rule drops unknown
 * attributes silently, which would quietly disarm a test instead of failing it.
 */
final class DowngradePhpUnitAttributesRector extends AbstractRector
{
    /**
     * @var array<string, string>
     */
    private const ATTRIBUTE_TO_TAG = [
        'PHPUnit\Framework\Attributes\DataProvider' => 'dataProvider',
        'PHPUnit\Framework\Attributes\TestDox' => 'testdox',
        'PHPUnit\Framework\Attributes\Group' => 'group',
        'PHPUnit\Framework\Attributes\Depends' => 'depends',
        'PHPUnit\Framework\Attributes\CoversClass' => 'covers',
        'PHPUnit\Framework\Attributes\Test' => 'test',
    ];

    private PhpDocInfoFactory $phpDocInfoFactory;

    private DocBlockUpdater $docBlockUpdater;

    public function __construct(PhpDocInfoFactory $phpDocInfoFactory, DocBlockUpdater $docBlockUpdater)
    {
        $this->phpDocInfoFactory = $phpDocInfoFactory;
        $this->docBlockUpdater = $docBlockUpdater;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Downgrade PHPUnit metadata attributes to docblock annotations', [new CodeSample(
            "#[DataProvider('provideCases')]\npublic function testIt(): void\n{\n}",
            "/**\n * @dataProvider provideCases\n */\npublic function testIt(): void\n{\n}"
        )]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class, ClassMethod::class];
    }

    /**
     * @param Class_|ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        if ([] === $node->attrGroups) {
            return null;
        }

        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($node);
        $hasChanged = false;

        foreach ($node->attrGroups as $groupKey => $attrGroup) {
            foreach ($attrGroup->attrs as $attrKey => $attribute) {
                $attributeName = $attribute->name->toString();
                if (!str_starts_with($attributeName, 'PHPUnit\\')) {
                    continue;
                }

                if (!isset(self::ATTRIBUTE_TO_TAG[$attributeName])) {
                    throw new \RuntimeException(\sprintf('No PHP 7.4 annotation is mapped for PHPUnit attribute "%s".', $attributeName));
                }

                $phpDocInfo->addPhpDocTagNode(new PhpDocTagNode('@' . self::ATTRIBUTE_TO_TAG[$attributeName], new GenericTagValueNode($this->resolveTagValue($attribute))));
                unset($attrGroup->attrs[$attrKey]);
                $hasChanged = true;
            }

            if ([] === $attrGroup->attrs) {
                unset($node->attrGroups[$groupKey]);
            }
        }

        if (!$hasChanged) {
            return null;
        }

        $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($node);

        return $node;
    }

    private function resolveTagValue(Attribute $attribute): string
    {
        $parts = [];
        foreach ($attribute->args as $arg) {
            if (!$arg->value instanceof String_) {
                throw new \RuntimeException(\sprintf('PHPUnit attribute "%s" takes an argument that is not a plain string.', $attribute->name->toString()));
            }

            $parts[] = $arg->value->value;
        }

        return implode(' ', $parts);
    }
}
