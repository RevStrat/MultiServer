<?php

namespace RevStrat\MultiServer;
use SilverStripe\Control\SimpleResourceURLGenerator;
use InvalidArgumentException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Manifest\ManifestFileFinder;
use SilverStripe\Core\Manifest\ModuleResource;
use SilverStripe\Core\Manifest\ResourceURLGenerator;
use SilverStripe\Core\Path;
use SilverStripe\Control\Director;

class HashingResourceURLGenerator extends SimpleResourceURLGenerator {
    /*
     * @var string
     */
    private $nonceStyle;

    /*
     * Set the style of nonce-suffixes to use, or null to disable
     * Currently only "mtime" is allowed
     *
     * @param string|null $nonceStyle The style of nonces to apply, or null to disable
     * @return $this
     */
    public function setNonceStyle($nonceStyle)
    {
        if ($nonceStyle && $nonceStyle !== 'md5') {
            throw new InvalidArgumentException('The only allowed NonceStyle is md5');
        }
        $this->nonceStyle = $nonceStyle;
        return $this;
    }

        /**
     * Return the URL for a resource, prefixing with Director::baseURL() and suffixing with a nonce
     *
     * @param string|ModuleResource $relativePath File or directory path relative to BASE_PATH
     * @return string Doman-relative URL
     * @throws InvalidArgumentException If the resource doesn't exist
     */
    public function urlForResource($relativePath)
    {
        $query = '';
        if ($relativePath instanceof ModuleResource) {
            list($exists, $absolutePath, $relativePath) = $this->resolveModuleResource($relativePath);
        } elseif (Director::is_absolute_url($relativePath)) {
            // Path is not relative, and probably not of this site
            return $relativePath;
        } else {
            // Save querystring for later
            if (strpos($relativePath, '?') !== false) {
                list($relativePath, $query) = explode('?', $relativePath);
            }

            // Determine lookup mechanism based on existence of public/ folder.
            // From 5.0 onwards only resolvePublicResource() will be used.
            if (!Director::publicDir()) {
                list($exists, $absolutePath, $relativePath) = $this->resolveUnsecuredResource($relativePath);
            } else {
                list($exists, $absolutePath, $relativePath) = $this->resolvePublicResource($relativePath);
            }
        }
        if (!$exists) {
            trigger_error("File {$relativePath} does not exist", E_USER_NOTICE);
        }

        // Switch slashes for URL
        $relativeURL = Convert::slashes($relativePath, '/');

        // Apply url rewrites
        $rules = Config::inst()->get(static::class, 'url_rewrites') ?: [];
        foreach ($rules as $from => $to) {
            $relativeURL = preg_replace($from, $to, $relativeURL);
        }

        // Apply nonce
        // Don't add nonce to directories
        if ($this->nonceStyle && $exists && is_file($absolutePath)) {
            switch ($this->nonceStyle) {
                case 'md5':
                    if ($query) {
                        $query .= '&';
                    }
                    $query .= "m=" . hash_file('md5', $absolutePath);
                    break;
            }
        }

        // Add back querystring
        if ($query) {
            $relativeURL .= '?' . $query;
        }

        return Director::baseURL() . $relativeURL;
    }
}