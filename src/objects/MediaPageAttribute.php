<?php

namespace nglasl\mediawesome;

use SilverStripe\Forms\DateField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;

/**
 *	This is essentially the versioned join between `MediaPage` and `MediaAttribute`, since each page will have different content for an attribute.
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 * @property ?string $Content
 * @property int $MediaPageID
 * @property int $MediaAttributeID
 * @method \nglasl\mediawesome\MediaPage MediaPage()
 * @method \nglasl\mediawesome\MediaAttribute MediaAttribute()
 * @mixin \SilverStripe\Versioned\Versioned
 */
class MediaPageAttribute extends DataObject
{
    private static string $table_name = 'MediaPageAttribute';

    private static array $db = [
        'Content' => 'HTMLText'
    ];

    private static array $has_one = [
        'MediaPage' => MediaPage::class,
        'MediaAttribute' => MediaAttribute::class
    ];

    private static array $summary_fields = [
        'Title',
        'Content'
    ];

    #[\Override]
    public function canDelete($member = null)
    {

        return false;
    }

    #[\Override]
    public function getTitle()
    {
        $mediaAttribute = $this->MediaAttribute();
        return $mediaAttribute && $mediaAttribute->isInDB() ? $mediaAttribute->Title : null;
    }

    #[\Override]
    public function getCMSFields()
    {

        $fields = parent::getCMSFields();
        $fields->removeByName('MediaPageID');
        $fields->removeByName('MediaAttributeID');

        // Determine the field type.

        if (strrpos($this->getTitle(), 'Date')) {

            // The user expects this to be a date attribute.

            $fields->replaceField('Content', DateField::create(
                'Content'
            ));
        } else {

            // This is most commonly a simple attribute, so a HTML field only complicates things for the user.

            $fields->replaceField('Content', TextareaField::create(
                'Content'
            ));
        }

        // Allow extension customisation.

        $this->extend('updateMediaPageAttributeCMSFields', $fields);
        return $fields;
    }

}
