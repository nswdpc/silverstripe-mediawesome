<?php

namespace nglasl\mediawesome;

use SilverStripe\ORM\DataObject;

/**
 *	This is a CMS tag for a media page.
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 * @property string $Title
 */
class MediaTag extends DataObject
{
    private static string $table_name = 'MediaTag';

    private static array $db = [
        'Title' => 'Varchar(255)'
    ];

    private static string $default_sort = 'Title';

    #[\Override]
    public function canView($member = null)
    {

        return true;
    }

    #[\Override]
    public function canEdit($member = null)
    {

        return true;
    }

    #[\Override]
    public function canCreate($member = null, $context = [])
    {

        return true;
    }

    #[\Override]
    public function canDelete($member = null)
    {

        return false;
    }

    /**
     *	Confirm that the current tag is valid.
     */

    #[\Override]
    public function validate()
    {

        $result = parent::validate();

        // Confirm that the current tag has been given a title and doesn't already exist.

        if ($result->isValid() && !$this->Title) {
            $result->addError('"Title" required!');
        } elseif ($result->isValid() && MediaTag::get_one(MediaTag::class, [
            'ID != ?' => $this->ID,
            'Title = ?' => $this->Title
        ])) {
            $result->addError('Tag already exists!');
        }

        // Allow extension customisation.

        $this->extend('validateMediaTag', $result);
        return $result;
    }

}
