<?php

namespace nglasl\mediawesome;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FileHandleField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;

/**
 *  Displays customised media content relating to the respective media type.
 *  @author Nathan Glasl <nathan@symbiote.com.au>
 * @property string $ExternalLink
 * @property ?string $Abstract
 * @property ?string $Date
 * @property int $MediaTypeID
 * @method \nglasl\mediawesome\MediaType MediaType()
 * @method \SilverStripe\ORM\ManyManyThroughList<\nglasl\mediawesome\MediaPageAttribute> MediaAttributes()
 * @method \SilverStripe\ORM\ManyManyList<\SilverStripe\Assets\Image> Images()
 * @method \SilverStripe\ORM\ManyManyList<\SilverStripe\Assets\File> Attachments()
 * @method \SilverStripe\ORM\ManyManyList<\nglasl\mediawesome\MediaTag> Categories()
 * @method \SilverStripe\ORM\ManyManyList<\nglasl\mediawesome\MediaTag> Tags()
 * @mixin \nglasl\mediawesome\MediaPageLinkExtension
 */
class MediaPage extends \Page
{
    private static string $table_name = 'MediaPage';

    private static array $db = [
        'ExternalLink' => 'Varchar(255)',
        'Abstract' => 'Text',
        'Date' => 'Date'
    ];

    private static array $has_one = [
        'MediaType' => MediaType::class
    ];

    private static array $many_many = [
        'MediaAttributes' => [
            'through' => MediaPageAttribute::class, // This is essentially the versioned join.
            'from' => 'MediaPage',
            'to' => 'MediaAttribute'
        ],
        'Images' => Image::class,
        'Attachments' => File::class,
        'Categories' => MediaTag::class,
        'Tags' => MediaTag::class
    ];

    private static array $owns = [
        'MediaPageAttributes',
        'Images',
        'Attachments'
    ];

    private static array $defaults = [
        'ShowInMenus' => 0
    ];

    private static array $searchable_fields = [
        'Title',
        'ExternalLink',
        'Abstract',
        'Tagging'
    ];

    private static bool $can_be_root = false;

    /**
     * This value can be either a string or an array
     * @inheritdoc
     * @phpstan-ignore silverstan.configurationProperty.invalid
     */
    private static string|array $allowed_children = 'none';

    private static string $default_parent = MediaHolder::class;

    private static string $class_description = 'Blog, Event, News, Publication <strong>or Custom Media</strong>';

    private static string $cms_icon = 'nglasl/silverstripe-mediawesome: client/images/page.png';

    /**
     *  The default media types and their respective attributes.
     */

    private static array $type_defaults = [];

    #[\Override]
    public function requireDefaultRecords()
    {

        parent::requireDefaultRecords();

        // Determine whether this requires an SS3 to SS4 migration.

        if (MediaAttribute::get()->filter('MediaTypeID', 0)->exists()) {

            // The problem is that class name mapping happens after this, but we need it right now to query pages.

            foreach ([
                'SiteTree',
                'SiteTree_Live',
                'SiteTree_Versions'
            ] as $table) {
                $update = new SQLUpdate(
                    $table,
                    [
                        'ClassName' => MediaPage::class
                    ],
                    [
                        'ClassName' => 'MediaPage'
                    ]
                );
                $update->execute();
            }

            // Retrieve the existing media attributes.

            $attributes = new SQLSelect(
                '*',
                'MediaAttribute',
                ['"LinkID" <> 0','"MediaPageID" <> 0'],
                ['LinkID' => 'ASC']
            );
            $attributes = $attributes->execute();
            if ($attributes) {

                // With the results from above, delete these to prevent data integrity issues.

                $delete = new SQLDelete(
                    'MediaAttribute',
                    ['"LinkID" <> 0','"MediaPageID" <> 0']
                );
                $delete->execute();

                // Migrate the existing media attributes.

                foreach ($attributes as $existing) {
                    $page = MediaPage::get()->byID($existing['MediaPageID']);
                    if (!$page) {

                        // This page may no longer exist.

                        continue;
                    }

                    if ($existing['LinkID'] == -1) {

                        // Instantiate a new attribute for each "master" attribute.

                        $attribute = MediaAttribute::create();
                        $attribute->ID = $existing['ID'];
                        $attribute->Created = $existing['Created'];
                        $attribute->Title = $existing['Title'];
                        $attribute->OriginalTitle = $existing['OriginalTitle'];
                        $attribute->MediaTypeID = $page->MediaTypeID;
                        $attribute->write();
                    } else {
                        $attribute = MediaAttribute::get()->byID($existing['LinkID']);
                    }

                    // Each page will have different content for a media attribute.

                    $content = $existing['Content'] ?? null;
                    $page->MediaAttributes()->add($attribute, [
                        'Content' => $content
                    ]);

                    // The attributes are versioned, but should only be published when it's considered safe to do so.

                    if ($page->isPublished() && !$page->isModifiedOnDraft()) {
                        $page->publishRecursive();
                    }
                }
            }
        }

        // Retrieve existing "start time" attributes.

        $attributes = MediaAttribute::get()->filter([
            'MediaType.Title' => 'Event',
            'OriginalTitle' => 'Start Time'
        ]);
        foreach ($attributes as $attribute) {

            // These should now be "time" attributes.

            $attribute->Title = 'Time';
            $attribute->OriginalTitle = 'Time';
            $attribute->write();
        }

        // Instantiate the default media types and their respective attributes.

        foreach (static::config()->get('type_defaults') as $name => $attributes) {

            // Confirm that the media type doesn't already exist before creating it.

            $type = MediaType::get()->filter([
                'Title' => $name
            ])->first();
            if (!$type) {
                $type = MediaType::create();
                $type->Title = $name;
                $type->write();
                DB::alteration_message("\"{$name}\" Media Type", 'created');
            }

            if (is_array($attributes)) {
                foreach ($attributes as $attribute) {

                    // Confirm that the media attributes don't already exist before creating them.

                    if (!MediaAttribute::get()->filter([
                        'MediaTypeID' => $type->ID,
                        'OriginalTitle' => $attribute
                    ])->first()) {
                        $new = MediaAttribute::create();
                        $new->Title = $attribute;
                        $new->MediaTypeID = $type->ID;
                        $new->write();
                        DB::alteration_message("\"{$name}\" > \"{$attribute}\" Media Attribute", 'created');
                    }
                }
            }
        }
    }

    #[\Override]
    public function getCMSFields()
    {

        $fields = parent::getCMSFields();

        // Display the media type as read only.
        $mediaType = $this->MediaType();
        $mediaTypeTitle = $mediaType ? strip_tags(trim($mediaType->Title ?? '')) : '';
        $fields->addFieldToTab('Root.Main', ReadonlyField::create(
            'Type',
            'Type',
            $mediaTypeTitle
        ), 'Title');

        // Display a notification that the parent holder contains mixed children.
        /** @var MediaHolder $parent **/
        $parent = $this->getParent();
        if ($parent && $parent->getMediaHolderChildren()->exists()) {
            Requirements::css('nglasl/silverstripe-mediawesome: client/css/mediawesome.css');
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'MediaNotification',
                "<p class='mediawesome notification'><strong>Mixed " . htmlspecialchars($mediaTypeTitle) . " Holder</strong></p>"
            ), 'Type');
        }

        // Display the remaining media page fields.

        $fields->addFieldToTab('Root.Main', TextField::create(
            'ExternalLink'
        )->setDescription('An <strong>optional</strong> redirect URL to the media source. If this field is not empty, it will make this page act like a RedirectorPage.'), 'URLSegment');
        $fields->addFieldToTab('Root.Main', DateField::create(
            'Date'
        ), 'Content');

        // Allow customisation of categories and tags respective to the current page.

        $tags = MediaTag::get()->map()->toArray();
        $fields->findOrMakeTab('Root.CategoriesTags', 'Categories and Tags');
        $fields->addFieldToTab('Root.CategoriesTags', $categoriesList = ListboxField::create(
            'Categories',
            'Categories',
            $tags
        ));
        $fields->addFieldToTab('Root.CategoriesTags', $tagsList = ListboxField::create(
            'Tags',
            'Tags',
            $tags
        ));
        if (!$tags) {
            $categoriesList->setAttribute('disabled', 'true');
            $tagsList->setAttribute('disabled', 'true');
        }

        // Display an abstract field for content summarisation.

        $fields->addfieldToTab('Root.Main', $abstract = TextareaField::create(
            'Abstract'
        ), 'Content');
        $abstract->setDescription('A concise summary of the content');

        // Allow customisation of the media type attributes.

        $fields->addFieldToTab('Root.Main', GridField::create(
            'MediaPageAttributes',
            "{$mediaTypeTitle} Attributes",
            $this->MediaPageAttributes(),
            GridFieldConfig_RecordEditor::create()->removeComponentsByType(GridFieldAddNewButton::class)
        )->addExtraClass('pb-2'), 'Content');

        // Allow customisation of images and attachments.

        $type = strtolower($mediaTypeTitle);
        $fields->findOrMakeTab('Root.ImagesAttachments', 'Images and Attachments');
        $fields->addFieldToTab('Root.ImagesAttachments', $images = Injector::inst()->create(
            FileHandleField::class,
            'Images'
        ));
        $images->setAllowedFileCategories('image/supported');
        $images->setFolderName("media-{$type}/{$this->ID}/images");

        $fields->addFieldToTab('Root.ImagesAttachments', $attachments = Injector::inst()->create(
            FileHandleField::class,
            'Attachments'
        ));
        $attachments->setFolderName("media-{$type}/{$this->ID}/attachments");

        // Allow extension customisation.

        $this->extend('updateMediaPageCMSFields', $fields);
        return $fields;
    }

    /**
     *  Confirm that the current page is valid.
     */

    #[\Override]
    public function validate(): \SilverStripe\Core\Validation\ValidationResult
    {

        $parent = $this->getParent();

        // The URL segment will conflict with a year/month/day/media format when numeric.

        if (is_numeric($this->URLSegment) || !($parent instanceof MediaHolder) || ($this->MediaTypeID && ($parent->MediaTypeID != $this->MediaTypeID))) {

            // Customise a validation error message.

            if (is_numeric($this->URLSegment)) {
                $message = '"URL Segment" must not be numeric!';
            } elseif (!($parent instanceof MediaHolder)) {
                $message = 'The parent needs to be a published media holder!';
            } else {
                $message = "The media holder type doesn't match this!";
            }

            $error = new HTTPResponse_Exception($message, 403);
            $error->getResponse()->addHeader('X-Status', rawurlencode($message));

            // Allow extension customisation.

            $this->extend('validateMediaPage', $error);
            throw $error;
        }

        return parent::validate();
    }

    #[\Override]
    public function onBeforeWrite()
    {

        parent::onBeforeWrite();

        // Set the default media page date.

        if (!$this->Date) {
            $this->Date = date('Y-m-d');
        }

        // Confirm that the external link exists.

        if ($this->ExternalLink) {
            // The following code was taken from RedirectorPage::onBeforeWrite()
            // on SilverStripe 4.1.1
            if (!str_starts_with($this->ExternalLink, '//')) {
                $urlParts = parse_url($this->ExternalLink);
                if ($urlParts) {
                    if (empty($urlParts['scheme'])) {
                        // no scheme, assume http
                        $this->ExternalLink = 'http://' . $this->ExternalLink;
                    } elseif (!in_array(
                        $urlParts['scheme'],
                        [
                            'http',
                            'https',
                        ],
                        true
                    )) {
                        // we only allow http(s) urls
                        $this->ExternalLink = '';
                    }
                } else {
                    // malformed URL to reject
                    $this->ExternalLink = '';
                }
            }

            $file_headers = @get_headers($this->ExternalLink);
            if ($file_headers === [] || $file_headers === false || strripos($file_headers[0], '404 Not Found')) {
                $this->ExternalLink = null;
            }
        }

        // Apply the parent holder media type.
        /** @var MediaHolder $parent **/
        $parent = $this->getParent();
        if ($parent) {
            $type = $parent->MediaType();
            if ($type->exists()) {
                $this->MediaTypeID = $type->ID;
            }
        }
    }

    #[\Override]
    public function onAfterWrite()
    {

        parent::onAfterWrite();

        // This triggers for both a save and publish, causing duplicate attributes to appear.

        if (Versioned::get_stage() === 'Stage') {

            // The attributes of the respective type need to appear on this page.
            $mediaType = $this->MediaType();
            $mediaTypeAttributes = $mediaType ? $mediaType->MediaAttributes() : [];
            foreach ($mediaTypeAttributes as $attribute) {
                $this->MediaAttributes()->add($attribute);
            }
        }
    }

    /**
     * Retrieve the formated prefix for the page link, based on this page's parent URLFormatting value
     * If there is no parent, or if the URLFormatting is not set, the prefix is not returned
     */
    public function getUrlFormattingPrefix(?string $action = null): string
    {
        $parent = $this->getParent();
        if (!$parent || !$parent->isInDB()) {
            return '';
        }

        // remove the trailing / from the formatting value, defined in the enum
        $format = trim(rtrim((string)$parent->URLFormatting, '/'));
        if ($format !== '-') {
            // all current formats are date formats
            return $this->dbObject('Date')->Format($format);
        } else {
            return '';
        }
    }

    /**
     * Return the external link value for this page, validated
     */
    public function getExternalLink(): string
    {
        $externalLink = trim($this->getField('ExternalLink') ?? '');
        if ($externalLink !== '' && filter_var($externalLink, FILTER_VALIDATE_URL) !== false) {
            return $externalLink;
        } else {
            return '';
        }
    }

    /**
     * Determine the Link by using the media holder's defined URL format.
     * If an external link is set, this is returned
     */
    #[\Override]
    public function Link($action = null)
    {
        $externalLink = $this->getExternalLink();
        if ($externalLink !== '') {
            return $externalLink;
        } else {
            // call parent::Link, which itself calls RelativeLink()
            return parent::Link($action);
        }
    }

    /**
     *  If the page has an external link supplied, return that as the alternate absolute link
     */
    #[\Override]
    public function AbsoluteLink($action = null)
    {
        $externalLink = $this->getExternalLink();
        if ($externalLink !== '') {
            return $externalLink;
        } else {
            return parent::AbsoluteLink($action);
        }
    }

    /**
     *  Retrieve the versioned attribute join records, since these are what we're editing.
     */

    public function MediaPageAttributes(): DataList
    {

        return MediaPageAttribute::get()->filter('MediaPageID', $this->ID);
    }

    /**
     *  Retrieve a specific attribute for use in templates.
     *
     *  @parameter <{ATTRIBUTE}> string
     */

    public function getAttribute(string $title): ?MediaAttribute
    {
        // @phpstan-ignore return.type
        return $this->MediaAttributes()->filter('OriginalTitle', $title)->first();
    }

    /**
     *  Retrieve a specific attribute for use in templates.
     *
     *  @parameter <{ATTRIBUTE}> string
     */

    public function Attribute(string $title): ?MediaAttribute
    {

        // This provides consistency when it comes to defining parameters from the template.

        return $this->getAttribute($title);
    }

}
