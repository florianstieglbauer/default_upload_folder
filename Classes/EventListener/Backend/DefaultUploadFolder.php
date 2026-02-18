<?php

declare(strict_types=1);

namespace BeechIt\DefaultUploadFolder\EventListener\Backend;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\File\ExtendedFileUtility;
use TYPO3\CMS\Core\Resource\Event\AfterDefaultUploadFolderWasResolvedEvent;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;


class DefaultUploadFolder
{
    private const DEFAULT_UPLOAD_FOLDERS = 'default_upload_folders.';
    private const DEFAULT_FOR_ALL_TABLES = 'defaultForAllTables';

    /**
     * @param AfterDefaultUploadFolderWasResolvedEvent $event
     * @return void
     */
    public function __invoke(AfterDefaultUploadFolderWasResolvedEvent $event): void
    {
        /** @var Folder $uploadFolder */
        $uploadFolder = $event->getUploadFolder() ?? null;
        $table = $event->getTable();
        $field = $event->getFieldName();
        $pid = $event->getPid();
        $pageTs = BackendUtility::getPagesTSconfig($pid);
        $userTsConfig = $GLOBALS['BE_USER']->getTsConfig();
        $subFolder = '';
        if ($table !== null && $field !== null) {
            $subFolder = $this->getDefaultUploadFolderForTableAndField($table, $field, $pageTs, $userTsConfig, (int)$pid);
        }

        if (trim($subFolder) === '' && $field !== null) {
            $subFolder = $this->getDefaultUploadFolderForTable($table, $pageTs, $userTsConfig, (int)$pid);
        }

        if (trim($subFolder) === '') {
            $subFolder = $this->getDefaultUploadFolderForAllTables($pageTs, $userTsConfig, (int)$pid);
        }

        // Folder by combined identifier
        if (preg_match('/[0-9]+:/', $subFolder)) {
            try {
                $uploadFolder = GeneralUtility::makeInstance(ResourceFactory::class)->getFolderObjectFromCombinedIdentifier(
                    $subFolder
                );
            } catch (FolderDoesNotExistException $e) {
                $uploadFolder = $this->createUploadFolder($subFolder);
            } catch (InsufficientFolderAccessPermissionsException $e) {
                $uploadFolder = null;
            }
        }

        if (trim($subFolder) && $uploadFolder instanceof Folder && $uploadFolder->hasFolder($subFolder)) {
            $uploadFolder = $uploadFolder->getSubfolder($subFolder);
        }

        if ($uploadFolder instanceof FolderInterface) {
            $event->setUploadFolder($uploadFolder);
        }

        $this->showUploadFolderInfo($table, $field, $subFolder, (int)$pid);
    }

    /**
     * Zeigt Informationen zum aktuellen Upload-Ordner an
     *
     * @param string|null $table
     * @param string|null $field
     * @param string $uploadFolder
     * @param int $pid
     */
    protected function showUploadFolderInfo(?string $table, ?string $field, string $uploadFolder, int $pid): void
    {


        // Prüfen, ob die notwendigen Daten vorhanden sind
        if (!$table || !$field || !$uploadFolder) {
            return;
        }


        // Prüfe die Gültigkeit des Ordners und bereite Informationen vor
        $uploadFolderPath = $uploadFolder;
        $isValidPath = true;
        $errorMessage = '';

        if (preg_match('/^(\d+):(.*)$/', $uploadFolder, $matches)) {


            $storageUid = (int)$matches[1];
            $folderPath = $matches[2];

            try {
                // Versuche den Storage zu laden
                $storage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject($storageUid);

                if (!$storage->isOnline()) {
                    $isValidPath = false;
                    $errorMessage = 'Storage ist falsch oder nicht erreichbar';
                } else {
                    $configuration = $storage->getConfiguration();
                    $basePath = $configuration['basePath'] ?? '';

                    if ($basePath) {
                        $uploadFolderPath = $basePath . $folderPath;
                    }
                }
            } catch (\Exception $e) {
                $isValidPath = false;
                $errorMessage = 'Storage mit ID ' . $storageUid . ' existiert nicht';
            }
        }

        // JavaScript-Code einfügen, um die Information im Formular anzuzeigen
        $pageRenderer = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class);

        $js = '
        if (!window.uploadFolderInfo) window.uploadFolderInfo = {};
        window.uploadFolderInfo[' . json_encode($field) . '] = ' . json_encode($uploadFolderPath) . ';
        ';

        $pageRenderer->addJsInlineCode('uploadFolderData_' . $field, $js, false, false, true);
        $pageRenderer->addJsFile('EXT:default_upload_folder/Resources/Public/JavaScript/test.js');

    }


    /**
     * Create upload folder
     *
     * @param $combinedFolderIdentifier
     * @return Folder|null
     */
    private function createUploadFolder($combinedFolderIdentifier): ?Folder
    {
        if (!str_contains($combinedFolderIdentifier, ':')) {
            return null;
        }
        $parts = explode(':', $combinedFolderIdentifier);
        // Split $combinedFolderIdentifier into target and data(folders to be created) due possible permissions mismatch.
        // When an user has access to a subdir by filemount but not access to the full storage, the root target (/) is checked for permission.
        // Therefore, an exception will be thrown. Checking and specifying the target more precise this will be avoid.
        $dirs = explode('/', trim($parts[1], '/'));
        $lastItem = array_pop($dirs);
        $nonExistingDirs = [];
        while ($lastItem !== null) {
            $nonExistingDirs = [$lastItem, ...$nonExistingDirs];
            try {
                GeneralUtility::makeInstance(ResourceFactory::class)
                    ->getFolderObjectFromCombinedIdentifier(
                        $parts[0] . ':/' . implode('/', $dirs)
                    );
                break;
            } catch (FolderDoesNotExistException $folderDoesNotExistException) {
            }
            $lastItem = array_pop($dirs);
        }
        $data = [
            'newfolder' => [
                0 => [
                    'data' => implode('/', $nonExistingDirs),
                    'target' => $parts[0] . ':/' . implode('/', $dirs),
                ],
            ],
        ];


        $fileProcessor = GeneralUtility::makeInstance(ExtendedFileUtility::class);
        $fileProcessor->setExistingFilesConflictMode(DuplicationBehavior::RENAME);
        $fileProcessor->setActionPermissions();
        $fileProcessor->start($data);
        $fileProcessor->processData();

        return GeneralUtility::makeInstance(ResourceFactory::class)->getFolderObjectFromCombinedIdentifier(
            $combinedFolderIdentifier
        );
    }

    /**
     * Get default upload folder for table and field
     *
     * @param string $table
     * @param string $field
     * @param array $defaultPageTs
     * @param array $userTsConfig
     * @param int $pid
     * @return string
     */
    protected function getDefaultUploadFolderForTableAndField(
        string $table,
        string $field,
        array  $defaultPageTs,
        array  $userTsConfig,
        int    $pid
    ): string
    {
        $subFolder = $defaultPageTs[self::DEFAULT_UPLOAD_FOLDERS][$table . '.'][$field] ?? '';
        $config = $defaultPageTs[self::DEFAULT_UPLOAD_FOLDERS][$table . '.'][$field . '.'] ?? [];
        $subFolder = $this->checkAndConvertForVariable($subFolder, $config, $pid);
        if (empty($subFolder)) {
            $subFolder = $userTsConfig[self::DEFAULT_UPLOAD_FOLDERS][$table . '.'][$field] ?? '';
            $config = $userTsConfig[self::DEFAULT_UPLOAD_FOLDERS][$table . '.'][$field . '.'] ?? [];
            $subFolder = $this->checkAndConvertForVariable($subFolder, $config, $pid);
        }
        return $subFolder;
    }

    /**
     * Get default upload folder for table
     *
     * @param string $table
     * @param array $defaultPageTs
     * @param array $userTsConfig
     * @param int $pid
     * @return string
     */
    protected function getDefaultUploadFolderForTable(
        string $table,
        array  $defaultPageTs,
        array  $userTsConfig,
        int    $pid
    ): string
    {
        $subFolder = $defaultPageTs[self::DEFAULT_UPLOAD_FOLDERS][$table] ?? '';

        $config = $defaultPageTs[self::DEFAULT_UPLOAD_FOLDERS][$table . '.'] ?? [];
        $subFolder = $this->checkAndConvertForVariable($subFolder, $config, $pid);
        if (empty($subFolder)) {
            $subFolder = $userTsConfig[self::DEFAULT_UPLOAD_FOLDERS][$table] ?? '';
            $config = $userTsConfig[self::DEFAULT_UPLOAD_FOLDERS][$table . '.'] ?? [];
            $subFolder = $this->checkAndConvertForVariable($subFolder, $config, $pid);
        }
        return $subFolder;
    }

    /**
     * Get default upload folder for all tables
     *
     * @param array $defaultPageTs
     * @param array $userTsConfig
     * @param int $pid
     * @return string
     */
    protected function getDefaultUploadFolderForAllTables(
        array $defaultPageTs,
        array $userTsConfig,
        int   $pid
    ): string
    {
        $subFolder = $defaultPageTs[self::DEFAULT_UPLOAD_FOLDERS][self::DEFAULT_FOR_ALL_TABLES] ?? '';

        $config = $defaultPageTs[self::DEFAULT_UPLOAD_FOLDERS][self::DEFAULT_FOR_ALL_TABLES . '.'] ?? [];
        $subFolder = $this->checkAndConvertForVariable($subFolder, $config, $pid);
        if (empty($subFolder)) {
            $subFolder = $userTsConfig[self::DEFAULT_UPLOAD_FOLDERS][self::DEFAULT_FOR_ALL_TABLES] ?? '';

            $config = $userTsConfig[self::DEFAULT_UPLOAD_FOLDERS][self::DEFAULT_FOR_ALL_TABLES . '.'] ?? [];
            $subFolder = $this->checkAndConvertForVariable($subFolder, $config, $pid);
        }
        return $subFolder;
    }

    protected function slugify(string $string): string
    {
        $string = trim($string);
        $string = mb_strtolower($string, 'UTF-8');

        // Umlaute ersetzen
        $umlaute = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
        $string = strtr($string, $umlaute);

        // Sonderzeichen entfernen
        $string = preg_replace('/[^a-z0-9\s\-]/', '', $string);

        // Leerzeichen und Doppelte Dashes zu einem
        $string = preg_replace('/[\s\-]+/', '-', $string);

        return trim($string, '-');
    }


    /**
     * Check and convert for variables
     *
     * @param $subFolder
     * @param $config
     * @param $pid
     * @return string
     */
    protected function checkAndConvertForVariable($subFolder, $config, int $pid): string
    {



        if (trim($subFolder) === '') {
            return $subFolder;
        }

        // Handle date variables
        //Exmaple: 1:user_upload/news/{y}/{m}/
        if (isset($config['dateformat']) && (int)$config['dateformat'] === 1) {
            $datePlaceholders = [
                '{Y}', '{y}',
                '{m}', '{n}',
                '{j}', '{d}',
                '{W}', '{w}',
            ];
            $dateReplacements = [
                date('Y'), date('y'),
                date('m'), date('n'),
                date('j'), date('d'),
                date('W'), date('w'),
            ];
            $subFolder = str_replace($datePlaceholders, $dateReplacements, $subFolder);
        }

        // Handle page related variables
        // Example: 1:user_upload/{title}/images/
        if (!empty($config['variableformat']) && (int)$config['variableformat'] === 1) {

            $fields = ['title', 'nav_title', 'subtitle'];
            $page = BackendUtility::getRecord('pages', $pid, implode(',', $fields)) ?: [];

            foreach ($fields as $field) {
                $subFolder = str_replace(
                    '{' . $field . '}',
                    $this->slugify($page[$field] ?? ''),
                    $subFolder
                );
            }
        }

        // Prüfen: Enthält der Subfolder noch Platzhalter {xyz} ?
        if (preg_match('/\{[^}]+\}/', $subFolder)) {
            return '';
        }

        return $subFolder;
    }
}
