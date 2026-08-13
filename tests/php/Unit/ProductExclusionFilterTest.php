<?php
/**
 * Unit tests for WC_Multi_Store_Product_Exclusion_Filter
 */

class ProductExclusionFilterTest extends WC_Multi_Store_TestCase
{
    /**
     * Test product is excluded when category matches
     */
    public function test_should_exclude_by_category(): void
    {
        $category_ids = [10, 20, 30];
        $tag_ids = [];
        $store_config = [
            'exclude_categories' => [20],
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertTrue($result);
    }

    /**
     * Test product is excluded when tag matches
     */
    public function test_should_exclude_by_tag(): void
    {
        $category_ids = [10];
        $tag_ids = [100, 200, 300];
        $store_config = [
            'exclude_tags' => [200],
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertTrue($result);
    }

    /**
     * Test product is not excluded when no match
     */
    public function test_should_not_exclude_when_no_match(): void
    {
        $category_ids = [10, 20, 30];
        $tag_ids = [100, 200];
        $store_config = [
            'exclude_categories' => [50, 60],
            'exclude_tags' => [500, 600],
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertFalse($result);
    }

    /**
     * Test empty exclusion rules don't exclude product
     */
    public function test_empty_exclusion_rules_dont_exclude(): void
    {
        $category_ids = [10, 20];
        $tag_ids = [100];
        $store_config = [];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertFalse($result);
    }

    /**
     * Test empty product categories/tags with exclusion rules
     */
    public function test_empty_product_terms_not_excluded(): void
    {
        $category_ids = [];
        $tag_ids = [];
        $store_config = [
            'exclude_categories' => [10, 20],
            'exclude_tags' => [100],
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertFalse($result);
    }

    /**
     * Test multiple exclusion rules - category match takes priority
     */
    public function test_multiple_exclusion_rules_category_first(): void
    {
        $category_ids = [10, 20];
        $tag_ids = [100, 200];
        $store_config = [
            'exclude_categories' => [10], // Will match
            'exclude_tags' => [500],       // Won't match
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertTrue($result);
    }

    /**
     * Test product excluded by any matching category (not all)
     */
    public function test_any_category_match_excludes(): void
    {
        $category_ids = [10, 20, 30, 40];
        $tag_ids = [];
        $store_config = [
            'exclude_categories' => [30], // Only one of the product's categories
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertTrue($result);
    }

    /**
     * Test product excluded by any matching tag (not all)
     */
    public function test_any_tag_match_excludes(): void
    {
        $category_ids = [];
        $tag_ids = [100, 200, 300, 400];
        $store_config = [
            'exclude_tags' => [300], // Only one of the product's tags
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertTrue($result);
    }

    /**
     * Test multiple categories in exclusion list
     */
    public function test_multiple_excluded_categories(): void
    {
        $category_ids = [50];
        $tag_ids = [];
        $store_config = [
            'exclude_categories' => [10, 20, 30, 40, 50], // 50 matches
        ];

        $result = WC_Multi_Store_Product_Exclusion_Filter::should_exclude_by_ids(
            $category_ids,
            $tag_ids,
            $store_config
        );

        $this->assertTrue($result);
    }
}
