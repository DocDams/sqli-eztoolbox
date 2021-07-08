<?php

namespace SQLI\EzToolboxBundle\Annotations;

use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use ReflectionClass;
use ReflectionException;
use SQLI\EzToolboxBundle\Annotations\Annotation\Entity as SQLIEntity;
use SQLI\EzToolboxBundle\Annotations\Annotation\EntityProperty as SQLIEntityProperty;
use SQLI\EzToolboxBundle\Annotations\Annotation\SQLIClassAnnotation;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class SQLIAnnotationManager
{
    /**
     * Classname of annotation
     * @var string
     */
    private $annotation;
    /** @var array */
    private $directories;
    /** @var Reader */
    private $annotationReader;
    /**
     * Project root directory
     * @var string
     */
    private $projectDir;

    public function __construct($annotation, $directories, $projectDir, Reader $annotationReader)
    {
        $this->annotation = $annotation;
        $this->directories = $directories;
        $this->projectDir = $projectDir;
        $this->annotationReader = $annotationReader;
    }

    /**
     * Returns all PHP classes annotated with annotation specified in service declaration (see services.yml)
     * @return array
     * @throws ReflectionException
     * @example service : sqli_admin_annotation_entities
     *
     */
    public function getAnnotatedClasses(): array
    {
        $annotations = $this->getSQLIAnnotations();

        // Only annotation in service declaration will be kept
        if (array_key_exists($this->annotation, $annotations)) {
            return $annotations[$this->annotation];
        }

        return [];
    }

    /**
     * Return all PHP classes annotated with an SQLIClassAnnotation
     * For each class, all properties will be defined
     *
     * @return array
     * @throws ReflectionException
     */
    protected function getSQLIAnnotations(): array
    {
        $annotatedClasses = [];

        // Scan all files into directories defined in configuration
        foreach ($this->directories as $entitiesMapping) {
            $directory = $entitiesMapping['directory'];
            $namespace = $entitiesMapping['namespace'];
            if (is_null($namespace)) {
                $namespace = str_replace('/', '\\', $directory);
            }

            $path = $this->projectDir . '/src/' . $directory;
            $finder = new Finder();
            $finder->depth(0)->files()->in($path);

            /** @var SplFileInfo $file */
            foreach ($finder as $file) {
                $className = $file->getBasename('.php');
                $classNamespace = "$namespace\\$className";
                // Create reflection class from generated namespace to read annotation
                $class = new ReflectionClass($classNamespace);

                // Search if $class use an SQLIClassAnnotation
                $classAnnotation = $this
                    ->annotationReader
                    ->getClassAnnotation($class, SQLIEntity::class);
                // Check if $class use Doctrine\Entity annotation
                $classDoctrineAnnotation = $this
                    ->annotationReader
                    ->getClassAnnotation($class, Entity::class);

                if (!$classAnnotation || !$classDoctrineAnnotation) {
                    // No SQLIClassAnnotation or isn't an entity, ignore her
                    continue;
                }

                // Prepare properties
                $properties = [];
                $compoundPrimaryKey = [];

                $reflectionProperties = $class->getProperties();
                foreach ($reflectionProperties as $reflectionProperty) {
                    // Accessibility of each property
                    $accessibility = "public"; // public
                    if ($reflectionProperty->isPrivate()) {
                        $accessibility = "private"; // private
                    } elseif ($reflectionProperty->isProtected()) {
                        $accessibility = "protected"; // protected
                    }

                    // Try to get an SQLIPropertyAnnotation
                    $visible = true;
                    $readonly = false;
                    $required = true;
                    $columnType = null;
                    $description = null;
                    $choices = null;
                    $extraLink = null;
                    $onetomany = null;
                    $manytoone = null;

                    $propertyAnnotation = $this
                        ->annotationReader
                        ->getPropertyAnnotation($reflectionProperty, SQLIEntityProperty::class);

                    if ($propertyAnnotation instanceof SQLIEntityProperty) {
                        // Check if a visibility information defined on entity's property thanks to 'visible' annotation
                        $visible = $propertyAnnotation->isVisible();
                        // Check if property must be only in readonly
                        $readonly = $propertyAnnotation->isReadonly();
                        // Get property description
                        $description = $propertyAnnotation->getDescription();
                        // Get choices
                        $choices = $propertyAnnotation->getChoices();
                        $extraLink = $propertyAnnotation->getExtraLink();
                    }

                    // Check if nullable is sets to true
                    $nullablePropertyAnnotation = $this
                        ->annotationReader
                        ->getPropertyAnnotation($reflectionProperty, Column::class);

                    if ($nullablePropertyAnnotation) {
                        $columnType = $nullablePropertyAnnotation->type;
                        $required = $columnType == "boolean" ? false : !boolval($nullablePropertyAnnotation->nullable);
                    }

                    // Check if there is a onetomany or manytoone attribute in the entity
                    $oneToManyPropertyAnnotation = $this
                        ->annotationReader
                        ->getPropertyAnnotation($reflectionProperty, OneToMany::class);

                    $onetomany = $this->setBidirectionalParam($oneToManyPropertyAnnotation);

                    $manyToOnePropertyAnnotation = $this
                        ->annotationReader
                        ->getPropertyAnnotation($reflectionProperty, ManyToOne::class);

                    $manytoone = $this->setBidirectionalParam($manyToOnePropertyAnnotation);

                    $properties[$reflectionProperty->getName()] = [
                        'accessibility' => $accessibility,
                        'visible' => $visible,
                        'readonly' => $readonly,
                        'required' => $required,
                        'type' => $columnType,
                        'description' => $description,
                        'choices' => $choices,
                        'extra_link' => $extraLink,
                        'onetomany' => $onetomany,
                        'manytoone' => $manytoone
                    ];

                    // Build primary key from Doctrine\Id annotation
                    if ($this->annotationReader->getPropertyAnnotation($reflectionProperty, Id::class)) {
                        $compoundPrimaryKey[] = $reflectionProperty->getName();
                    }
                }

                /** @var SQLIClassAnnotation $classAnnotation */
                $annotationClassname = substr(strrchr(get_class($classAnnotation), '\\'), 1);

                $annotatedClasses[$annotationClassname][$classNamespace] =
                    [
                        'classname' => $className,
                        'annotation' => $classAnnotation,
                        'properties' => $properties,
                        'primary_key' => $compoundPrimaryKey,
                    ];
            }
        }

        return $annotatedClasses;
    }

    /**
     * @param $bidirectionalPropertyAnnotation
     * @throws ReflectionException
     */
    protected function setBidirectionalParam($bidirectionalPropertyAnnotation): ?array
    {
        if (!is_null($bidirectionalPropertyAnnotation)) {
            $bidirectionalParam = array();
            $targetEntityName = $bidirectionalPropertyAnnotation->targetEntity;
            $targetEntity = new ReflectionClass($targetEntityName);
            $targetEntityProperties = $targetEntity->getProperties();
            foreach ($targetEntityProperties as $targetEntityProperty) {
                $primaryKey = $this
                    ->annotationReader
                    ->getPropertyAnnotation($targetEntityProperty, Id::class);
                if (!is_null($primaryKey)) {
                    $bidirectionalParam["targetEntityPKey"] = $targetEntityProperty->getName();
                }
            }
            $bidirectionalParam['targetEntity'] = $bidirectionalPropertyAnnotation->targetEntity;
            if (property_exists($bidirectionalPropertyAnnotation, "mappedBy")) {
                $bidirectionalParam['mappedBy'] = $bidirectionalPropertyAnnotation->mappedBy;
            }
            return $bidirectionalParam;
        }
        return null;
    }
}
