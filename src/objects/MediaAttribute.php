<?php

namespace nglasl\mediawesome;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

/**
 *	This is a CMS attribute for a media type.
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 * @property string $Title
 * @property ?string $OriginalTitle
 * @property int $MediaTypeID
 * @method \nglasl\mediawesome\MediaType MediaType()
 * @method \SilverStripe\ORM\ManyManyList<\nglasl\mediawesome\MediaPage> MediaPages()
 */
class MediaAttribute extends DataObject
{
    private static string $table_name = 'MediaAttribute';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'OriginalTitle' => 'Varchar(255)'
    ];

    private static array $has_one = [
        'MediaType' => MediaType::class
    ];

    private static array $belongs_many_many = [
        'MediaPages' => MediaPage::class . '.MediaAttributes'
    ];

    #[\Override]
    public function canView($member = null)
    {

        return true;
    }

    #[\Override]
    public function canEdit($member = null)
    {

        return $this->checkPermissions($member);
    }

    #[\Override]
    public function canCreate($member = null, $context = [])
    {

        return $this->checkPermissions($member);
    }

    #[\Override]
    public function canDelete($member = null)
    {

        // Determine whether this is being used.

        $current = Versioned::get_stage();
        foreach (singleton(Versioned::class)->getVersionedStages() as $stage) {
            Versioned::set_stage($stage);
            if ($this->MediaPages()->exists() && $this->MediaPages()->where('MediaPageAttribute.Content IS NOT NULL')->exists()) {
                return false;
            }
        }

        Versioned::set_stage($current);

        // Determine whether this is user created.

        $typeDefaults = MediaPage::config()->get('type_defaults');
        $mediaType = $this->MediaType();
        $title = $mediaType && $mediaType->isInDB() ? trim($mediaType->Title ?? '') : '';
        if ($title !== '') {
            return !isset($typeDefaults[$title]) || !in_array($this->OriginalTitle, $typeDefaults[$title]);
        } else {
            return false;
        }
    }

    /**
     *	Determine access for the current CMS user from the site configuration permissions.
     *
     *	@parameter <{CURRENT_MEMBER}> member
     *	@return boolean
     */

    public function checkPermissions($member = null)
    {

        // Retrieve the current site configuration permissions for customisation of media.

        $configuration = SiteConfig::current_site_config();
        return Permission::check($configuration->MediaPermission, 'any', $member);
    }

    #[\Override]
    public function getCMSFields()
    {

        $fields = parent::getCMSFields();
        $fields->removeByName('OriginalTitle');
        $fields->removeByName('MediaTypeID');
        $fields->removeByName('MediaPages');

        // Allow extension customisation.

        $this->extend('updateMediaAttributeCMSFields', $fields);
        return $fields;
    }

    /**
     *	Confirm that the current attribute is valid.
     */

    #[\Override]
    public function validate(): \SilverStripe\Core\Validation\ValidationResult
    {

        $result = parent::validate();

        // Confirm that the current attribute has been given a title.

        if ($result->isValid() && !$this->Title) {
            $result->addError('"Title" required!');
        }

        // Allow extension customisation.

        $this->extend('validateMediaAttribute', $result);
        return $result;
    }

    #[\Override]
    public function onBeforeWrite()
    {

        parent::onBeforeWrite();

        // Set the original title of the current attribute for use in templates.

        if (!$this->OriginalTitle) {
            $this->OriginalTitle = $this->Title;
        }
    }

    #[\Override]
    public function onAfterWrite()
    {

        parent::onAfterWrite();

        // This needs to appear on media pages of the respective type.

        foreach (MediaPage::get()->filter('MediaTypeID', $this->MediaTypeID) as $page) {
            $page->MediaAttributes()->add($this);
        }
    }

    #[\Override]
    public function onAfterDelete()
    {

        parent::onAfterDelete();

        // Clean up the pages associated with this.

        $current = Versioned::get_stage();
        foreach (singleton(Versioned::class)->getVersionedStages() as $stage) {
            Versioned::set_stage($stage);
            MediaPageAttribute::get()->filter('MediaAttributeID', $this->ID)->removeAll();
        }

        Versioned::set_stage($current);
    }

    /**
     *	Retrieve a class name of the current attribute for use in templates.
     */
    public function getTemplateClass(): string
    {

        return strtolower((string) $this->OriginalTitle);
    }

}
