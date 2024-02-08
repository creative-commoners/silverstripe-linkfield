<?php declare(strict_types=1);

namespace SilverStripe\LinkField\Models;

use InvalidArgumentException;
use ReflectionException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CompositeValidator;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\LinkField\Services\LinkTypeService;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Forms\Tip;

/**
 * A Link Data Object. This class should be treated as abstract. You should never directly interact with a plain Link
 * instance
 *
 * Note that links should be added via a has_one or has_many relation, NEVER a many_many relation. This is because
 * some functionality such as the can* methods rely on having a single Owner.
 *
 * @property string $Title
 * @property bool $OpenInNew
 */
class Link extends DataObject
{
    private static $table_name = 'LinkField_Link';

    private static array $db = [
        'LinkText' => 'Varchar',
        'OpenInNew' => 'Boolean',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        // Note that this handles one-to-many relations AND one-to-one relations.
        // Any has_one pointing at Link will be intentionally double handled - this allows us to use the owner
        // for permission checks and to link back to the owner from reports, etc.
        // See also the Owner method.
        'Owner' => [
            'class' => DataObject::class,
            DataObjectSchema::HAS_ONE_MULTI_RELATIONAL => true,
        ],
    ];

    private static $default_sort = 'Sort';

    private static array $extensions = [
        Versioned::class,
    ];

    /**
     * In-memory only property used to change link type
     * This case is relevant for CMS edit form which doesn't use React driven UI
     * This is a workaround as changing the ClassName directly is not fully supported in the GridField admin
     */
    private ?string $linkType = null;

    /**
     * Set the priority of this link type in the CMS menu
     */
    private static int $menu_priority = 100;

    /**
     * The css class for the icon to display for this link type
     */
    private static $icon = 'font-icon-link';

    public function getDescription(): string
    {
        return '';
    }

    public function scaffoldLinkFields(array $data): FieldList
    {
        return $this->getCMSFields();
    }

    public function LinkTypeHandlerName(): string
    {
        return 'FormBuilderModal';
    }

    /**
     * @return FieldList
     * @throws ReflectionException
     */
    public function getCMSFields(): FieldList
    {
        $this->beforeUpdateCMSFields(function (FieldList $fields) {
            $linkTypes = $this->getLinkTypes();

            $linkTextField = $fields->dataFieldByName('LinkText');
            $linkTextField->setTitle(_t(__CLASS__ . '.LINK_TEXT_TITLE', 'Link text'));
            $linkTextField->setTitleTip(new Tip(_t(
                self::class . '.LINK_TEXT_TEXT_DESCRIPTION',
                'If left blank, an appropriate default will be used on the front-end',
            )));

            $fields->removeByName('Sort');

            $openInNewField = $fields->dataFieldByName('OpenInNew');
            $openInNewField->setTitle(_t(__CLASS__ . '.OPEN_IN_NEW_TITLE', 'Open in new window?'));

            if (static::class === self::class) {
                // Add a link type selection field for generic links
                $fields->addFieldsToTab(
                    'Root.Main',
                    [
                        $linkTypeField = DropdownField::create(
                            'LinkType',
                            _t(__CLASS__ . '.LINK_TYPE_TITLE', 'Link Type'),
                            $linkTypes
                        ),
                    ],
                    'LinkText'
                );

                $linkTypeField->setEmptyString('-- select type --');
            }
        });
        $this->afterUpdateCMSFields(function (FieldList $fields) {
            // Move the LinkText and OpenInNew fields to the bottom of the form if it hasn't been removed in
            // a subclasses getCMSFields() method
            foreach (['LinkText', 'OpenInNew'] as $name) {
                $field = $fields->dataFieldByName($name);
                if ($field) {
                    $fields->removeByName($name);
                    $fields->addFieldToTab('Root.Main', $field);
                }
            }
        });

        return parent::getCMSFields();
    }

    /**
     * @return CompositeValidator
     */
    public function getCMSCompositeValidator(): CompositeValidator
    {
        $validator = parent::getCMSCompositeValidator();

        if (static::class === self::class) {
            // Make Link type mandatory for generic links
            $validator->addValidator(RequiredFields::create([
                'LinkType',
            ]));
        }

        return $validator;
    }

    /**
     * Form hook defined in @see Form::saveInto()
     * We use this to work with an in-memory only field
     *
     * @param $value
     */
    public function saveLinkType($value)
    {
        $this->linkType = $value;
    }

    public function onBeforeWrite(): void
    {
        // Detect link type change and update the class accordingly
        if ($this->linkType && DataObject::singleton($this->linkType) instanceof Link) {
            $this->setClassName($this->linkType);
            $this->populateDefaults();
            $this->forceChange();
        }

        // Ensure a Sort value is set and that it's one larger than any other Sort value for
        // this owner relation so that newly created Links on MultiLinkField's are properly sorted
        if (!$this->Sort) {
            $this->Sort = self::get()->filter([
                'OwnerID' => $this->OwnerID,
                'OwnerRelation' => $this->OwnerRelation,
            ])->max('Sort') + 1;
        }

        parent::onBeforeWrite();
    }

    function setData($data): Link
    {
        if (is_string($data)) {
            $data = json_decode($data, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(
                    _t(
                        __CLASS__ . '.INVALID_JSON',
                        '"{class}": Decoding json string failred with "{error}"',
                        [
                            'class' => static::class,
                            'error' => json_last_error_msg(),
                        ],
                        sprintf(
                            '"%s": Decoding json string failred with "%s"',
                            static::class,
                            json_last_error_msg(),
                        ),
                    ),
                );
            }
        } elseif ($data instanceof Link) {
            $data = $data->jsonSerialize();
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException(
                _t(
                    __CLASS__ . '.INVALID_DATA_TO_ARRAY',
                    '"{class}": Could not convert $data to an array.',
                    ['class' => static::class],
                    sprintf('%s: Could not convert $data to an array.', static::class),
                ),
            );
        }

        $typeKey = $data['typeKey'] ?? null;

        if (!$typeKey) {
            throw new InvalidArgumentException(
                _t(
                    __CLASS__ . '.DATA_HAS_NO_TYPEKEY',
                    '"{class}": $data does not have a typeKey.',
                    ['class' => static::class],
                    sprintf('%s: $data does not have a typeKey.', static::class),
                ),
            );
        }

        $type = LinkTypeService::create()->byKey($typeKey);

        if (!$type) {
            throw new InvalidArgumentException(
                _t(
                    __CLASS__ . '.NOT_REGISTERED_LINKTYPE',
                    '"{class}": "{typekey}" is not a registered Link Type.',
                    [
                        'class' => static::class,
                        'typekey' => $typeKey
                    ],
                    sprintf('"%s": "%s" is not a registered Link Type.', static::class, $typeKey),
                ),
            );
        }

        $jsonData = $this;

        if ($this->ClassName !== get_class($type)) {
            if ($this->isInDB()) {
                $jsonData = $this->newClassInstance(get_class($type));
            } else {
                $jsonData = Injector::inst()->create(get_class($type));
            }
        }

        foreach ($data as $key => $value) {
            if ($jsonData->hasField($key)) {
                $jsonData->setField($key, $value);
            }
        }

        return $jsonData;
    }

    public function jsonSerialize(): mixed
    {
        $typeKey = LinkTypeService::create()->keyByClassName(static::class);

        if (!$typeKey) {
            return [];
        }

        // TODO: this could lead to data disclosure - we should only return the fields that are actually needed
        $data = $this->toMap();
        $data['typeKey'] = $typeKey;

        unset($data['ClassName']);
        unset($data['RecordClassName']);

        $data['Title'] = $this->getTitle();

        return $data;
    }

    public function loadLinkData(array $data): Link
    {
        $link = new static();

        foreach ($data as $key => $value) {
            if ($link->hasField($key)) {
                $link->setField($key, $value);
            }
        }

        return $link;
    }

    /**
     * Return a rendered version of this form.
     *
     * This is returned when you access a form as $FormObject rather
     * than <% with FormObject %>
     *
     * @return DBHTMLText
     */
    public function forTemplate()
    {
        // First look for a subclass of the email template e.g. EmailLink.ss which may be defined
        // in a project. Fallback to using the generic Link.ss template which this module provides
        return $this->renderWith([static::class, self::class]);
    }

    /**
     * This method should be overridden by any subclasses
     */
    public function getURL(): string
    {
        return '';
    }

    public function getVersionedState(): string
    {
        if (!$this->exists()) {
            return 'unsaved';
        }
        if ($this->hasExtension(Versioned::class)) {
            if ($this->isPublished()) {
                if ($this->isModifiedOnDraft()) {
                    return 'modified';
                }
                return 'published';
            }
            return 'draft';
        }
        // Unversioned - links are saved in the modal so there is no 'dirty state' and
        // when undversioned saved is the same thing as published
        return 'unversioned';
    }

    /**
     * Get the owner of this link, if there is one.
     *
     * Returns null if the reciprocal relation is a has_one which no longer contains this link
     * or if there simply is no actual owner record in the db.
     */
    public function Owner(): ?DataObject
    {
        $owner = $this->getComponent('Owner');

        // Since the has_one is being stored in two places, double check the owner
        // actually still owns this record. If not, return null.
        if ($this->OwnerRelation && $owner->getRelationType($this->OwnerRelation) === 'has_one') {
            $idField = "{$this->OwnerRelation}ID";
            if ($owner->$idField !== $this->ID) {
                return null;
            }
        }

        // Return null if there simply is no owner
        if (!$owner || !$owner->isInDB()) {
            return null;
        }

        return $owner;
    }

    public function canView($member = null)
    {
        return $this->canPerformAction(__FUNCTION__, $member);
    }

    public function canEdit($member = null)
    {
        return $this->canPerformAction(__FUNCTION__, $member);
    }

    public function canDelete($member = null)
    {
        return $this->canPerformAction(__FUNCTION__, $member);
    }

    public function canCreate($member = null, $context = [])
    {
        // Allow extensions to override permission checks
        $results = $this->extendedCan(__FUNCTION__, $member, $context);
        if (isset($results)) {
            return $results;
        }

        // Assume anyone can create a link by default - there's no way to determine
        // what the "owner" record is going to be ahead of time, but if the user
        // can't edit the owner then the linkfield will be read-only anyway, so we
        // can rely on that to determine who can create links.
        return true;
    }

    public function can($perm, $member = null, $context = [])
    {
        $check = ucfirst(strtolower($perm));
        return match ($check) {
            'View', 'Create', 'Edit', 'Delete' => $this->{"can$check"}($member, $context),
            default => parent::can($perm, $member, $context)
        };
    }

    private function canPerformAction(string $canMethod, $member, $context = [])
    {
        // Allow extensions to override permission checks
        $results = $this->extendedCan($canMethod, $member, $context);
        if (isset($results)) {
            return $results;
        }

        // If we have an owner, rely on it to tell us what we can and can't do
        $owner = $this->Owner();
        if ($owner && $owner->exists()) {
            // Can delete or create links if you can edit its owner.
            if ($canMethod === 'canCreate' || $canMethod === 'canDelete') {
                $canMethod = 'canEdit';
            }
            return $owner->$canMethod($member, $context);
        }

        // Default to DataObject's permission checks
        return parent::$canMethod($member, $context);
    }

    /**
     * Get all link types except the generic one
     *
     * @throws ReflectionException
     */
    private function getLinkTypes(): array
    {
        $classes = ClassInfo::subclassesFor(self::class);
        $types = [];

        foreach ($classes as $class) {
            if ($class === self::class) {
                continue;
            }

            $types[$class] = ClassInfo::shortName($class);
        }

        return $types;
    }

    public function getTitle(): string
    {
        // If we have link text, we can just bail out without any changes
        if ($this->LinkText) {
            return $this->LinkText;
        }

        $defaultLinkTitle = $this->getDefaultTitle();

        $this->extend('updateDefaultLinkTitle', $defaultLinkTitle);

        return $defaultLinkTitle;
    }

    public function getDefaultTitle(): string
    {
        $default = $this->getDescription() ?: $this->getURL();
        if (!$default) {
            $default = _t(static::class . '.MISSING_DEFAULT_TITLE', '(No value provided)');
        }
        return $default;
    }

    /**
     * This method process the defined singular_name of Link class
     * to get the short code of the Link class name.
     * Or If the name is not defined (by redefining $singular_name in the subclass),
     * this use the class name. The Link prefix is removed from the class name
     * and the resulting name is converted to lowercase.
     * Example: Link => link, EmailLink => email, FileLink => file, SiteTreeLink => sitetree
     */
    public function getShortCode(): string
    {
        return strtolower(rtrim(ClassInfo::shortName($this), 'Link')) ?? '';
    }

    /**
     * The title that will be displayed in the dropdown
     * for selecting the link type to create.
     * Subclasses should override this.
     * It will use the singular_name by default.
     */
    public function getMenuTitle(): string
    {
        return $this->i18n_singular_name();
    }
}
