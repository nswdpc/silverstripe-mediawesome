<?php

namespace nglasl\mediawesome;

use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;

/**
 *	This is a CMS type/category of media.
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 * @property string $Title
 * @method \SilverStripe\ORM\HasManyList<\nglasl\mediawesome\MediaAttribute> MediaAttributes()
 */
class MediaType extends DataObject
{
    private static string $table_name = 'MediaType';

    private static array $db = [
        'Title' => 'Varchar(255)'
    ];

    private static array $has_many = [
        'MediaAttributes' => MediaAttribute::class
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

        return $this->checkPermissions($member);
    }

    #[\Override]
    public function canDelete($member = null)
    {

        // Determine whether this is being used, and whether this is user created.

        $config = MediaPage::config();
        return
            !MediaHolder::get()->filter('MediaTypeID', $this->ID)->exists()
            && !isset($config->get('type_defaults')[$this->Title]);
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
        if ($this->Title) {

            // Display the title as read only.

            $fields->replaceField('Title', ReadonlyField::create(
                'Title'
            ));

            // Allow customisation of media type attributes, depending on the current CMS user permissions.

            $fields->removeByName('MediaAttributes');
            $configuration = ($this->checkPermissions() === false) ? GridFieldConfig_RecordViewer::create() : GridFieldConfig_RecordEditor::create();
            $fields->addFieldToTab('Root.Main', GridField::create(
                'MediaAttributes',
                'Custom Attributes',
                $this->MediaAttributes(),
                $configuration
            )->setModelClass(MediaAttribute::class));
        }

        // Allow extension customisation.

        $this->extend('updateMediaTypeCMSFields', $fields);
        return $fields;
    }

    /**
     *	Confirm that the current type is valid.
     */

    #[\Override]
    public function validate(): \SilverStripe\Core\Validation\ValidationResult
    {

        $result = parent::validate();

        // Confirm that the current type has been given a title and doesn't already exist.

        if ($result->isValid() && !$this->Title) {
            $result->addError('"Title" required!');
        } elseif ($result->isValid() && MediaType::get_one(MediaType::class, [
            'ID != ?' => $this->ID,
            'Title = ?' => $this->Title
        ])) {
            $result->addError('Type already exists!');
        }

        // Allow extension customisation.

        $this->extend('validateMediaType', $result);
        return $result;
    }

    #[\Override]
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $this->Title = strip_tags($this->Title ?? '');
    }

}
