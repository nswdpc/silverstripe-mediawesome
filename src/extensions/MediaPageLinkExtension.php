<?php

namespace nglasl\mediawesome;

use SilverStripe\Core\Extension;

/**
 * Provide link handling for nglasl\mediawesome\MediaPage
 * @extends \SilverStripe\Core\Extension<(\nglasl\mediawesome\MediaPage & static)>
 */
class MediaPageLinkExtension extends Extension
{
    public function updateRelativeLink(string &$link, ?string $base = null, ?string $action = null)
    {
        $mediaPage = $this->getOwner();
        $urlFormattingPrefix = $mediaPage->getUrlFormattingPrefix();
        if ($urlFormattingPrefix !== '') {
            $parts = explode("/", $link);
            $last = array_pop($parts);
            $parts[] = $urlFormattingPrefix;
            $parts[] = $last;
            $link = implode("/", $parts);
        }

    }

}
