<?php
/**
 * EavQueryBuilder — encapsulates EAV vs. legacy table dispatch for soentity.
 *
 * When an entity category has is_eav = true, its rows live in fm_bim_item
 * (keyed by location_id and the JSON column json_representation).  Otherwise
 * each category owns a dynamically-named legacy table:
 *   fm_{type}_{entity_id}_{cat_id}
 *
 * This class centralises that branching so callers do not need to test the
 * flag directly.
 *
 * @package property
 */

namespace App\modules\property\inc;

class EavQueryBuilder
{
    /**
     * @param bool   $isEav    Value of the is_eav flag from the category row.
     * @param string $type     Entity type string (e.g. 'entity').
     * @param int    $entityId Entity definition ID.
     * @param int    $catId    Category ID within the entity.
     */
    public function __construct(
        private readonly bool   $isEav,
        private readonly string $type,
        private readonly int    $entityId,
        private readonly int    $catId
    ) {
    }

    /**
     * Whether this category uses the EAV (fm_bim_item) storage model.
     */
    public function isEav(): bool
    {
        return $this->isEav;
    }

    /**
     * Return the storage table name for this category.
     *
     * EAV categories always use fm_bim_item; legacy categories use
     * the dynamically-named table.
     */
    public function tableName(): string
    {
        if ($this->isEav)
        {
            return 'fm_bim_item';
        }

        return "fm_{$this->type}_{$this->entityId}_{$this->catId}";
    }

    /**
     * Return the attribute column expression for a given column.
     *
     * EAV columns are accessed via the JSON operator; legacy columns
     * are plain SQL column references.
     *
     * @param string $column Raw column name (e.g. 'material').
     * @param string $tableAlias Optional table alias prefix.
     */
    public function columnExpr(string $column, string $tableAlias = ''): string
    {
        $prefix = $tableAlias ? "{$tableAlias}." : '';

        if ($this->isEav)
        {
            return "{$prefix}json_representation->>'{$column}'";
        }

        return "{$prefix}{$column}";
    }
}
