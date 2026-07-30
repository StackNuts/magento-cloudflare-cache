<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Admin repeater field for cache tag patterns that should never be sent to Cloudflare for
 * purging (see Model\Config::getExcludedTagPatterns()). A single "pattern" column - end a
 * pattern with * to match a prefix, otherwise it must match a tag exactly.
 */
class ExcludedTagPatterns extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('pattern', ['label' => __('Tag Pattern')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Pattern');
    }
}
