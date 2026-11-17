<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Cart;

use Centraliza\Connector\Core\Service\RecordDiff;
use Magento\Framework\Model\AbstractModel;

/**
 * Tells whether a cart, or an item or address of one, is being saved with
 * something different from what was loaded.
 */
class CartDiff
{
    /** Set on every save or used only while totals are collected. */
    private const IGNORED = ['updated_at', 'trigger_recollect', 'totals_collected_flag'];

    public function __construct(
        private readonly RecordDiff $diff
    ) {
    }

    public function isNew(AbstractModel $model): bool
    {
        // A model saved for the first time was never loaded, so it has nothing original.
        return $model->getOrigData($model->getIdFieldName()) === null;
    }

    public function changed(AbstractModel $model): bool
    {
        if ($this->isNew($model)) {
            return true;
        }
        $original = (array)$model->getOrigData();
        // Collecting totals fills the model with working values that are not columns
        // of its table; what was loaded has exactly the stored columns.
        $current = array_intersect_key((array)$model->getData(), $original);

        foreach ($this->diff->changedFields($current, $original, self::IGNORED) as $field) {
            // Totals stored as NULL come back from the collectors as zero.
            if ($original[$field] === null && is_numeric($current[$field]) && (float)$current[$field] === 0.0) {
                continue;
            }

            return true;
        }

        return false;
    }
}
