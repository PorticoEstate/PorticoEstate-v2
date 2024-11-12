<?php

namespace App\traits;

trait SerializableTrait
{

    /**
     * @Exclude
     */
    private static $annotationCache = [];


    public function serialize(array $userRoles = [], bool $short = false): ?array
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();

        $defaultBehavior = $this->getClassDefaultBehavior($reflection);

        $serialized = [];

        foreach ($properties as $property)
        {
            $exposeAnnotation = $this->parseExposeAnnotation($property);
            $excludeAnnotation = $this->parseExcludeAnnotation($property);
            $shortAnnotation = $this->parseShortAnnotation($property);
            $serializeAsAnnotation = $this->parseSerializeAsAnnotation($property);
            $escapeStringAnnotation = $this->parseEscapeStringAnnotation($property);

            if ($excludeAnnotation)
            {
                continue; // Skip this property
            }
//            _debug_array(['ref' => $reflection, 't' => $property , 'short' => $shortAnnotation, 'forSho' => $short, 'as' => $serializeAsAnnotation]);
            if ($short && !$shortAnnotation)
            {

                continue; // Skip non-short properties when short serialization is requested
            }

            if ($exposeAnnotation || $defaultBehavior === 'expose')
            {
                $groups = $exposeAnnotation['groups'] ?? [];
                if (empty($groups) || array_intersect($groups, $userRoles))
                {
                    $property->setAccessible(true);
                    $value = $property->getValue($this);

                    // Handle string sanitization based on @EscapeString annotation
                    if (is_string($value))
                    {
                        if ($escapeStringAnnotation !== null)
                        {
                            $value = $this->processStringEscaping($value, $escapeStringAnnotation);
                        } elseif ($this->sanitizeStrings)
                        {
                            $value = $this->sanitizeString($value);
                        }
                    }


                    if ($serializeAsAnnotation)
                    {
                        $value = $this->serializeAs($value, $serializeAsAnnotation);
                    }

                    if ($value !== null)
                    {
                        $serialized[$property->getName()] = $value;
                    }
                }
            }
        }

        return !empty($serialized) ? $serialized : null;
    }

    /**
     * Parse @EscapeString annotation
     * @return array|null Returns null if no annotation is present
     */
    private function parseEscapeStringAnnotation(\ReflectionProperty $property): ?array
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['escapeString'])) {
            $docComment = $property->getDocComment();

            if (preg_match('/@EscapeString\((.*?)\)/', $docComment, $matches)) {
                $options = [];

                // Parse options if they exist
                if (!empty($matches[1])) {
                    preg_match_all('/(\w+)\s*=\s*([^,\)]+)/', $matches[1], $optionMatches, PREG_SET_ORDER);
                    foreach ($optionMatches as $match) {
                        $optionName = trim($match[1]);
                        $optionValue = trim($match[2], '"\'');
                        $options[$optionName] = $optionValue;
                    }
                }

                self::$annotationCache[$className]['properties'][$propertyName]['escapeString'] = $options;
            } else {
                self::$annotationCache[$className]['properties'][$propertyName]['escapeString'] = null;
            }
        }

        return self::$annotationCache[$className]['properties'][$propertyName]['escapeString'];
    }

    /**
     * Process string escaping based on annotation options
     */
    protected function processStringEscaping(string $value, array $options): string
    {
        $mode = $options['mode'] ?? 'default';

        switch ($mode) {
            case 'html':
                // Only decode HTML entities
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            case 'preserve_newlines':
                // Preserve newline characters while decoding other entities
                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return str_replace(['\n', '\r'], ["\n", "\r"], $decoded);

            case 'encode':
                // Encode special characters (useful for output to HTML)
                return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            case 'none':
                // Don't perform any escaping
                return $value;

            case 'default':
            default:
                // Full sanitization
                return $this->sanitizeString($value);
        }
    }

    /**
     * Sanitize a string value by decoding HTML entities and handling special characters
     */
    protected function sanitizeString(string $value): string
    {
        // First decode any HTML entities
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Handle any remaining special character sequences
        $decoded = preg_replace_callback(
            '/&#(\d+);/',
            function($matches) {
                return chr($matches[1]);
            },
            $decoded
        );

        // Clean up any double-encoded entities
        if (strpos($decoded, '&amp;') !== false) {
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $decoded;
    }

    private function serializeAs($value, array $serializeAsAnnotation): mixed
    {
        $type = $serializeAsAnnotation['type'];
        $of = $serializeAsAnnotation['of'];
        $useShort = $serializeAsAnnotation['short'] ?? false;

        if ($type === 'object')
        {
            return $this->serializeAsObject($value, $of, $useShort);
        } elseif ($type === 'array')
        {
            return $this->serializeAsArray($value, $of, $useShort);
        }

        return $value;
    }

    private function serializeAsObject($value, string $className, bool $short): ?array
    {
        if ($value === null)
        {
            return null;
        }

        if (!is_object($value))
        {
            $value = $this->instantiate($className, $value);
        }

        if ($value === null)
        {
            return null;
        }
        if (method_exists($value, 'serialize'))
        {
            $serialized = $value->serialize([], $short);
            return !empty($serialized) ? $serialized : null;
        }

        return $value;
    }

    private function serializeAsArray($value, string $className, bool $short): ?array
    {
        if (!is_array($value) || empty($value))
        {
            return null;
        }

        $serialized = array_map(function ($item) use ($className, $short)
        {
            if ($item === null)
            {
                return null;
            }

            if (!is_object($item))
            {
                $item = $this->instantiate($className, $item);
            }

            if ($item === null)
            {
                return null;
            }

            if (method_exists($item, 'serialize'))
            {
                return $item->serialize([], $short);
            }

            return $item;
        }, $value);

        $serialized = array_filter($serialized, function ($item)
        {
            return $item !== null;
        });

        return !empty($serialized) ? $serialized : null;
    }


    private function instantiate(string $className, $data)
    {
        if (!class_exists($className))
        {
            throw new \RuntimeException("Class {$className} does not exist");
        }

        $reflection = new \ReflectionClass($className);

        if (empty($data))
        {
            return null;
        }

        if ($reflection->getConstructor() && $reflection->getConstructor()->getNumberOfParameters() > 0)
        {
            return $reflection->newInstance($data);
        } else
        {
            $instance = $reflection->newInstance();
            if (is_array($data))
            {
                foreach ($data as $key => $value)
                {
                    if (property_exists($instance, $key))
                    {
                        $instance->$key = $value;
                    }
                }
            }
            return $instance;
        }
    }

    private function getClassDefaultBehavior(\ReflectionClass $reflection): string
    {
        $className = $reflection->getName();
        if (!isset(self::$annotationCache[$className]['defaultBehavior']))
        {
            $docComment = $reflection->getDocComment();
            if (strpos($docComment, '@Expose') !== false)
            {
                self::$annotationCache[$className]['defaultBehavior'] = 'expose';
            } elseif (strpos($docComment, '@Exclude') !== false)
            {
                self::$annotationCache[$className]['defaultBehavior'] = 'exclude';
            } else
            {
                self::$annotationCache[$className]['defaultBehavior'] = 'expose'; // Default to expose if no annotation is present
            }
        }
        return self::$annotationCache[$className]['defaultBehavior'];
    }

    private function parseExposeAnnotation(\ReflectionProperty $property): ?array
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['expose']))
        {
            $docComment = $property->getDocComment();
            if (preg_match('/@Expose(\(groups=\{"(.+?)"\}\))?/', $docComment, $matches))
            {
                self::$annotationCache[$className]['properties'][$propertyName]['expose'] = [
                    'groups' => isset($matches[2]) ? explode('","', $matches[2]) : []
                ];
            } else
            {
                self::$annotationCache[$className]['properties'][$propertyName]['expose'] = null;
            }
        }
        return self::$annotationCache[$className]['properties'][$propertyName]['expose'];
    }

    private function parseExcludeAnnotation(\ReflectionProperty $property): bool
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['exclude']))
        {
            $docComment = $property->getDocComment();
            self::$annotationCache[$className]['properties'][$propertyName]['exclude'] = strpos($docComment, '@Exclude') !== false;
        }
        return self::$annotationCache[$className]['properties'][$propertyName]['exclude'];
    }


    private function parseShortAnnotation(\ReflectionProperty $property): bool
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['short']))
        {
            $docComment = $property->getDocComment();
            self::$annotationCache[$className]['properties'][$propertyName]['short'] = strpos($docComment, '@Short') !== false;
        }
        return self::$annotationCache[$className]['properties'][$propertyName]['short'];
    }

    private function parseSerializeAsAnnotation(\ReflectionProperty $property): ?array
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();

        if (!isset(self::$annotationCache[$className]['properties'][$propertyName]['serializeAs']))
        {
            $docComment = $property->getDocComment();
            if (preg_match('/@SerializeAs\(type="(object|array)"(?:,\s*of="(.+?)")?(?:,\s*short=(true|false))?\)/', $docComment, $matches))
            {
                self::$annotationCache[$className]['properties'][$propertyName]['serializeAs'] = [
                    'type' => $matches[1],
                    'of' => $matches[2] ?? null,
                    'short' => isset($matches[3]) ? $matches[3] === 'true' : false
                ];
            } else
            {
                self::$annotationCache[$className]['properties'][$propertyName]['serializeAs'] = null;
            }
        }
        return self::$annotationCache[$className]['properties'][$propertyName]['serializeAs'];
    }
}