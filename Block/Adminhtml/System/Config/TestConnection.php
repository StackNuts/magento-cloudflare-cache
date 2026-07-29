<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\CloudflareCache\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Test Connection" button on the Cloudflare Configuration
 * admin form. Save the config first, then this purges the current store's
 * own hostname to prove the Zone ID/API token actually work end to end.
 */
class TestConnection extends Field
{
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        $this->setTemplate('StackNuts_CloudflareCache::system/config/testconnection.phtml');
        return $this;
    }

    public function render(AbstractElement $element)
    {
        $element = clone $element;
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $this->addData([
            'html_id' => $element->getHtmlId(),
            'ajax_url' => $this->_urlBuilder->getUrl('stacknuts_cloudflarecache/system_config/testconnection', [
                'website' => $this->getRequest()->getParam('website'),
                'store' => $this->getRequest()->getParam('store'),
            ]),
        ]);

        return $this->_toHtml();
    }
}
