<?php

namespace Elementareteilchen\Housekeeper\Helper;

use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileException;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Helper class for common command operations
 *
 * Provides static utility methods for file and folder operations
 * used across multiple command classes. Centralizes common functionality
 * to reduce code duplication.
 */
class CommandHelper
{
    /**
     * Get folder path from a combined path
     *
     * Extracts the folder path component from a full path
     *
     * @param string $path Full path including filename
     * @return string Folder path without the filename
     */
    public static function getFolderFromPath(string $path): string
    {
        return self::getFolderAndFile($path)[0];
    }

    /**
     * Split a path into folder and file parts
     *
     * @param mixed $path Full path to split
     * @return array [$folderPath, $fileName] Array containing folder path and filename
     */
    public static function getFolderAndFile(mixed $path): array
    {
        // Check if the path contains a colon followed by a slash
        if (preg_match('/^(\d+):\//', $path, $matches)) {
            $prefix = $matches[1] . ':/';
            $path = substr($path, strlen($prefix));
        } else {
            $prefix = '';
        }

        // Split the path into folder and file parts
        $parts = explode('/', $path);
        $fileName = array_pop($parts);
        $folderPath = $prefix . implode('/', $parts);

        // If the fileName is empty, it means the path ends with a slash
        if ($fileName === '') {
            $folderPath .= '/';
        }

        return [$folderPath, $fileName];
    }

    /**
     * Get a file or folder object from an identifier
     *
     * Retrieves a file or folder object and performs permission checks
     *
     * @param ResourceFactory $resourceFactory TYPO3 resource factory
     * @param string $identifier File or folder identifier
     * @return mixed File or folder object
     * @throws InvalidFileException If the identifier does not represent a valid file or folder
     * @throws InsufficientFileAccessPermissionsException If access to the file or folder is denied
     */
    public static function getFileObject(ResourceFactory $resourceFactory, string $identifier): mixed
    {
        $object = $resourceFactory->retrieveFileOrFolderObject($identifier);
        if (!is_object($object)) {
            throw new InvalidFileException('The identifier ' . $identifier . ' is not a file or directory!!', 1320122453);
        }
        if ($object->getStorage()->getUid() === 0) {
            throw new InsufficientFileAccessPermissionsException('You are not allowed to access files outside your storages', 1375889830);
        }
        return $object;
    }
}
