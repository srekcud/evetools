<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Sde;

use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use Doctrine\ORM\EntityManagerInterface;

final class ManufacturableProductSde
{
    public const int FEROX = 990_001;
    public const int HECATE = 990_002;
    public const int FERNITE_CARBIDE = 990_003;
    public const int UNPUBLISHED_PRODUCT = 990_004;
    public const int SVIPUL = 990_006;
    public const int FEROX_BLUEPRINT = 990_011;
    public const int HECATE_BLUEPRINT = 990_012;
    public const int COERCER_BLUEPRINT = 990_013;
    public const int FERNITE_CARBIDE_REACTION_FORMULA = 990_014;
    public const int UNPUBLISHED_PRODUCT_BLUEPRINT = 990_015;
    public const int SVIPUL_BLUEPRINT = 990_016;
    public const int SVIPUL_INVENTED_BLUEPRINT = 990_017;
    public const int THRASHER_BLUEPRINT = 990_018;
    public const int ABSENT_FROM_SDE = 990_099;
    public const int SINGLE_DIGIT_PRODUCT_ONE = 1;
    public const int SINGLE_DIGIT_PRODUCT_SEVEN = 7;
    private const int SINGLE_DIGIT_PRODUCT_ONE_BLUEPRINT = 990_021;
    private const int SINGLE_DIGIT_PRODUCT_SEVEN_BLUEPRINT = 990_027;
    public const int UNPUBLISHED_INVENTED_PRODUCT = 990_031;
    public const int UNPUBLISHED_INVENTED_PRODUCT_BLUEPRINT = 990_032;
    private const int UNPUBLISHED_INVENTED_PRODUCT_INVENTOR_BLUEPRINT = 990_033;
    public const int PRODUCT_OF_AN_INVENTED_REACTION_FORMULA = 990_034;
    public const int INVENTED_REACTION_FORMULA = 990_035;
    private const int INVENTED_REACTION_FORMULA_INVENTOR = 990_036;

    public const string FEROX_NAME = 'Ferox';
    public const string HECATE_NAME = 'Hecate';
    public const string FERNITE_CARBIDE_NAME = 'Fernite Carbide';
    public const string UNPUBLISHED_PRODUCT_NAME = 'Hecate Prototype Hull';
    public const string SVIPUL_NAME = 'Svipul';

    public const array FIXTURE_TYPE_IDS = [
        self::FEROX,
        self::HECATE,
        self::FERNITE_CARBIDE,
        self::UNPUBLISHED_PRODUCT,
        self::SVIPUL,
        self::FEROX_BLUEPRINT,
        self::HECATE_BLUEPRINT,
        self::COERCER_BLUEPRINT,
        self::FERNITE_CARBIDE_REACTION_FORMULA,
        self::UNPUBLISHED_PRODUCT_BLUEPRINT,
        self::SVIPUL_BLUEPRINT,
        self::SVIPUL_INVENTED_BLUEPRINT,
        self::THRASHER_BLUEPRINT,
    ];

    private const int CATEGORY_ID = 990_238_001;
    private const int PRODUCT_GROUP_ID = 990_238_101;
    private const int BLUEPRINT_GROUP_ID = 990_238_102;

    public static function persist(EntityManagerInterface $em): void
    {
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Fixture Category 238')->setPublished(true);
        $products = (new InvGroup())->setGroupId(self::PRODUCT_GROUP_ID)->setGroupName('Fixture Products 238')->setCategory($category)->setPublished(true);
        $blueprints = (new InvGroup())->setGroupId(self::BLUEPRINT_GROUP_ID)->setGroupName('Fixture Blueprints 238')->setCategory($category)->setPublished(true);
        $em->persist($category);
        $em->persist($products);
        $em->persist($blueprints);

        self::type($em, self::FEROX, self::FEROX_NAME, $products, true);
        self::type($em, self::HECATE, self::HECATE_NAME, $products, true);
        self::type($em, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_NAME, $products, true);
        self::type($em, self::UNPUBLISHED_PRODUCT, self::UNPUBLISHED_PRODUCT_NAME, $products, false);
        self::type($em, self::SVIPUL, self::SVIPUL_NAME, $products, true);
        self::type($em, self::FEROX_BLUEPRINT, 'Ferox Blueprint', $blueprints, true);
        self::type($em, self::HECATE_BLUEPRINT, 'Hecate Blueprint', $blueprints, true);
        self::type($em, self::COERCER_BLUEPRINT, 'Coercer Blueprint', $blueprints, true);
        self::type($em, self::FERNITE_CARBIDE_REACTION_FORMULA, 'Fernite Carbide Reaction Formula', $blueprints, true);
        self::type($em, self::UNPUBLISHED_PRODUCT_BLUEPRINT, 'Hecate Prototype Hull Blueprint', $blueprints, true);
        self::type($em, self::SVIPUL_BLUEPRINT, 'Svipul Blueprint', $blueprints, true);
        self::type($em, self::SVIPUL_INVENTED_BLUEPRINT, 'Svipul Advanced Blueprint', $blueprints, true);
        self::type($em, self::THRASHER_BLUEPRINT, 'Thrasher Blueprint', $blueprints, true);

        self::activityProduct($em, self::FEROX_BLUEPRINT, IndustryActivityType::Manufacturing, self::FEROX, 1);
        self::activityProduct($em, self::HECATE_BLUEPRINT, IndustryActivityType::Manufacturing, self::HECATE, 1);
        self::activityProduct($em, self::COERCER_BLUEPRINT, IndustryActivityType::Invention, self::HECATE_BLUEPRINT, 10);
        self::activityProduct($em, self::FERNITE_CARBIDE_REACTION_FORMULA, IndustryActivityType::Reaction, self::FERNITE_CARBIDE, 10_000);
        self::activityProduct($em, self::UNPUBLISHED_PRODUCT_BLUEPRINT, IndustryActivityType::Manufacturing, self::UNPUBLISHED_PRODUCT, 3);
        self::activityProduct($em, self::SVIPUL_BLUEPRINT, IndustryActivityType::Manufacturing, self::SVIPUL, 2);
        self::activityProduct($em, self::SVIPUL_INVENTED_BLUEPRINT, IndustryActivityType::Manufacturing, self::SVIPUL, 1);
        self::activityProduct($em, self::THRASHER_BLUEPRINT, IndustryActivityType::Invention, self::SVIPUL_INVENTED_BLUEPRINT, 5);

        $em->flush();
    }

    public static function persistProductsWithSingleDigitTypeIds(EntityManagerInterface $em): void
    {
        $products = $em->getReference(InvGroup::class, self::PRODUCT_GROUP_ID);
        $blueprints = $em->getReference(InvGroup::class, self::BLUEPRINT_GROUP_ID);
        self::type($em, self::SINGLE_DIGIT_PRODUCT_ONE, 'Fixture Hull One', $products, true);
        self::type($em, self::SINGLE_DIGIT_PRODUCT_SEVEN, 'Fixture Hull Seven', $products, true);
        self::type($em, self::SINGLE_DIGIT_PRODUCT_ONE_BLUEPRINT, 'Fixture Hull One Blueprint', $blueprints, true);
        self::type($em, self::SINGLE_DIGIT_PRODUCT_SEVEN_BLUEPRINT, 'Fixture Hull Seven Blueprint', $blueprints, true);
        self::activityProduct($em, self::SINGLE_DIGIT_PRODUCT_ONE_BLUEPRINT, IndustryActivityType::Manufacturing, self::SINGLE_DIGIT_PRODUCT_ONE, 4);
        self::activityProduct($em, self::SINGLE_DIGIT_PRODUCT_SEVEN_BLUEPRINT, IndustryActivityType::Manufacturing, self::SINGLE_DIGIT_PRODUCT_SEVEN, 6);
        $em->flush();
    }

    public static function persistUnpublishedInventedProductAndInventedReactionFormula(EntityManagerInterface $em): void
    {
        $products = $em->getReference(InvGroup::class, self::PRODUCT_GROUP_ID);
        $blueprints = $em->getReference(InvGroup::class, self::BLUEPRINT_GROUP_ID);
        self::type($em, self::UNPUBLISHED_INVENTED_PRODUCT, 'Sabre Prototype Hull', $products, false);
        self::type($em, self::UNPUBLISHED_INVENTED_PRODUCT_BLUEPRINT, 'Sabre Prototype Hull Blueprint', $blueprints, true);
        self::type($em, self::UNPUBLISHED_INVENTED_PRODUCT_INVENTOR_BLUEPRINT, 'Thrasher Prototype Blueprint', $blueprints, true);
        self::type($em, self::PRODUCT_OF_AN_INVENTED_REACTION_FORMULA, 'Fixture Polymer', $products, true);
        self::type($em, self::INVENTED_REACTION_FORMULA, 'Fixture Polymer Reaction Formula', $blueprints, true);
        self::type($em, self::INVENTED_REACTION_FORMULA_INVENTOR, 'Fixture Polymer Prototype Formula', $blueprints, true);
        self::activityProduct($em, self::UNPUBLISHED_INVENTED_PRODUCT_BLUEPRINT, IndustryActivityType::Manufacturing, self::UNPUBLISHED_INVENTED_PRODUCT, 8);
        self::activityProduct($em, self::UNPUBLISHED_INVENTED_PRODUCT_INVENTOR_BLUEPRINT, IndustryActivityType::Invention, self::UNPUBLISHED_INVENTED_PRODUCT_BLUEPRINT, 12);
        self::activityProduct($em, self::INVENTED_REACTION_FORMULA, IndustryActivityType::Reaction, self::PRODUCT_OF_AN_INVENTED_REACTION_FORMULA, 200);
        self::activityProduct($em, self::INVENTED_REACTION_FORMULA_INVENTOR, IndustryActivityType::Invention, self::INVENTED_REACTION_FORMULA, 9);
        $em->flush();
    }

    private static function type(EntityManagerInterface $em, int $typeId, string $typeName, InvGroup $group, bool $published): void
    {
        $em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setPublished($published));
    }

    private static function activityProduct(EntityManagerInterface $em, int $blueprintTypeId, IndustryActivityType $activity, int $productTypeId, int $quantity): void
    {
        $em->persist((new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activity->value)
            ->setProductTypeId($productTypeId)
            ->setQuantity($quantity));
    }
}
