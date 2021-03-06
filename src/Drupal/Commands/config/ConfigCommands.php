<?php
namespace Drush\Drupal\Commands\config;

use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Yaml\Parser;

class ConfigCommands extends DrushCommands
{

    /**
     * @var ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * @return ConfigFactoryInterface
     */
    public function getConfigFactory()
    {
        return $this->configFactory;
    }


    /**
     * ConfigCommands constructor.
     * @param ConfigFactoryInterface $configFactory
     */
    public function __construct($configFactory)
    {
        parent::__construct();
        $this->configFactory = $configFactory;
    }

    /**
     * Display a config value, or a whole configuration object.
     *
     * @command config:get
     * @validate-config-name
     * @interact-config-name
     * @param $config_name The config object name, for example "system.site".
     * @param $key The config key, for example "page.front". Optional.
     * @option source The config storage source to read. Additional labels may be defined in settings.php.
     * @option include-overridden Apply module and settings.php overrides to values.
     * @usage drush config:get system.site
     *   Displays the system.site config.
     * @usage drush config:get system.site page.front
     *   Gets system.site:page.front value.
     * @aliases cget,config-get
     */
    public function get($config_name, $key = '', $options = ['format' => 'yaml', 'source' => 'active', 'include-overridden' => false])
    {
        // Displaying overrides only applies to active storage.
        $factory = $this->getConfigFactory();
        $config = $options['include-overridden'] ? $factory->getEditable($config_name) : $factory->get($config_name);
        $value = $config->get($key);
        // @todo If the value is TRUE (for example), nothing gets printed. Is this yaml formatter's fault?
        return $key ? ["$config_name:$key" => $value] : $value;
    }

    /**
     * Set config value directly. Does not perform a config import.
     *
     * @command config:set
     * @validate-config-name
     * @todo @interact-config-name deferred until we have interaction for key.
     * @param $config_name The config object name, for example "system.site".
     * @param $key The config key, for example "page.front".
     * @param $value The value to assign to the config key. Use '-' to read from STDIN.
     * @option format Format to parse the object. Use "string" for string (default), and "yaml" for YAML.
     * // A convenient way to pass a multiline value within a backend request.
     * @option value The value to assign to the config key (if any).
     * @hidden-options value
     * @usage drush config:set system.site page.front node
     *   Sets system.site:page.front to "node".
     * @aliases cset,config-set
     */
    public function set($config_name, $key, $value = null, $options = ['format' => 'string', 'value' => null])
    {
        // This hidden option is a convenient way to pass a value without passing a key.
        $data = $options['value'] ?: $value;

        if (!isset($data)) {
            throw new \Exception(dt('No config value specified.'));
        }

        $config = $this->getConfigFactory()->getEditable($config_name);
        // Check to see if config key already exists.
        $new_key = $config->get($key) === null;

        // Special flag indicating that the value has been passed via STDIN.
        if ($data === '-') {
            $data = stream_get_contents(STDIN);
        }

        // Now, we parse the value.
        switch ($options['format']) {
            case 'yaml':
                $parser = new Parser();
                $data = $parser->parse($data, true);
        }

        if (is_array($data) && $this->io()->confirm(dt('Do you want to update or set multiple keys on !name config.', array('!name' => $config_name)))) {
            foreach ($data as $key => $value) {
                $config->set($key, $value);
            }
            return $config->save();
        } else {
            $confirmed = false;
            if ($config->isNew() && $this->io()->confirm(dt('!name config does not exist. Do you want to create a new config object?', array('!name' => $config_name)))) {
                $confirmed = true;
            } elseif ($new_key && $this->io()->confirm(dt('!key key does not exist in !name config. Do you want to create a new config key?', array('!key' => $key, '!name' => $config_name)))) {
                $confirmed = true;
            } elseif ($this->io()->confirm(dt('Do you want to update !key key in !name config?', array('!key' => $key, '!name' => $config_name)))) {
                $confirmed = true;
            }
            if ($confirmed && !\Drush\Drush::simulate()) {
                return $config->set($key, $data)->save();
            }
        }
    }

    /**
     * Open a config file in a text editor. Edits are imported after closing editor.
     *
     * @command config:edit
     * @validate-config-name
     * @interact-config-name
     * @param $config_name The config object name, for example "system.site".
     * @optionset_get_editor
     * @allow_additional_options config-import
     * @hidden-options source,partial
     * @usage drush config:edit image.style.large
     *   Edit the image style configurations.
     * @usage drush config:edit
     *   Choose a config file to edit.
     * @usage drush --bg config-edit image.style.large
     *   Return to shell prompt as soon as the editor window opens.
     * @aliases cedit,config-edit
     * @validate-module-enabled config
     */
    public function edit($config_name)
    {
        $config = $this->getConfigFactory()->get($config_name);
        $active_storage = $config->getStorage();
        $contents = $active_storage->read($config_name);

        // Write tmp YAML file for editing
        $temp_dir = drush_tempdir();
        $temp_storage = new FileStorage($temp_dir);
        $temp_storage->write($config_name, $contents);

        $exec = drush_get_editor();
        drush_shell_exec_interactive($exec, $temp_storage->getFilePath($config_name));

        // Perform import operation if user did not immediately exit editor.
        if (!$options['bg']) {
            $options = Drush::redispatchOptions()   + array('partial' => true, 'source' => $temp_dir);
            $backend_options = array('interactive' => true);
            return (bool) drush_invoke_process('@self', 'config-import', array(), $options, $backend_options);
        }
    }

    /**
     * Delete a configuration key, or a whole object.
     *
     * @command config:delete
     * @validate-config-name
     * @interact-config-name
     * @param $config_name The config object name, for example "system.site".
     * @param $key A config key to clear, for example "page.front".
     * @usage drush config:delete system.site
     *   Delete the the system.site config object.
     * @usage drush config:delete system.site page.front node
     *   Delete the 'page.front' key from the system.site object.
     * @aliases cdel,config-delete
     */
    public function delete($config_name, $key = null)
    {
        $config = $this->getConfigFactory()->getEditable($config_name);
        if ($key) {
            if ($config->get($key) === null) {
                throw new \Exception(dt('Configuration key !key not found.', array('!key' => $key)));
            }
            $config->clear($key)->save();
        } else {
            $config->delete();
        }
    }

    /**
     * Display status of configuration (differences between the filesystem configuration and database configuration).
     *
     * @command config:status
     * @option operation Operation A comma-separated list of operations to filter results.
     * @option prefix Prefix The config prefix. For example, "system". No prefix will return all names in the system.
     * @option string $label A config directory label (i.e. a key in \$config_directories array in settings.php).
     * @usage drush config:status
     *   Display configuration items that need to be synchronized.
     * @usage drush config:status --state=Identical
     *   Display configuration items that are in default state.
     * @usage drush config:status --state='Only in sync dir' --prefix=node.type.
     *   Display all content types that would be created in active storage on configuration import.
     * @usage drush config:status --state=Any --format=list
     *   List all config names.
     * @field-labels
     *   name: Name
     *   state: State
     * @default-fields name,state
     * @aliases cst,config-status
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function status($options = ['state' => 'Only in DB,Only in sync dir,Different', 'prefix' => '', 'label' => ''])
    {
        $config_list = array_fill_keys(
            $this->configFactory->listAll($options['prefix']),
            'Identical'
        );

        $directory = $this->getDirectory(null, $options['label']);
        $storage = $this->getStorage($directory);
        $state_map = [
            'create' => 'Only in DB',
            'update' => 'Only in sync dir',
            'delete' => 'Different',
        ];
        foreach ($this->getChanges($storage) as $collection) {
            foreach ($collection as $operation => $configs) {
                foreach ($configs as $config) {
                    if (!$options['prefix'] || strpos($config, $options['prefix']) === 0) {
                        $config_list[$config] = $state_map[$operation];
                    }
                }
            }
        }

        if ($options['state']) {
            $allowed_states = explode(',', $options['state']);
            if (!in_array('Any', $allowed_states)) {
                $config_list = array_filter($config_list, function ($state) use ($allowed_states) {
                     return in_array($state, $allowed_states);
                });
            }
        }

        ksort($config_list);

        $rows = [];
        $color_map = [
            'Only in DB' => 'green',
            'Only in sync dir' => 'yellow',
            'Different' => 'red',
            'Identical' => 'white',
        ];

        foreach ($config_list as $config => $state) {
            if ($options['format'] == 'table' && $state != 'Identical') {
                $state = "<fg={$color_map[$state]};options=bold>$state</>";
            }
            $rows[$config] = [
                'name' => $config,
                'state' => $state,
            ];
        }

        if ($rows) {
            return new RowsOfFields($rows);
        } else {
            $this->logger()->notice(dt('No differences between DB and sync directory.'));
        }
    }

    /**
     * Determine which configuration directory to use and return directory path.
     *
     * Directory path is determined based on the following precedence:
     *   1. User-provided $directory.
     *   2. Directory path corresponding to $label (mapped via $config_directories in settings.php).
     *   3. Default sync directory
     *
     * @param string $label
     *   A configuration directory label.
     * @param string $directory
     *   A configuration directory.
     */
    public function getDirectory($label, $directory = null)
    {
        // If the user provided a directory, use it.
        if (!empty($directory)) {
            if ($directory === true) {
                // The user did not pass a specific directory, make one.
                return drush_prepare_backup_dir('config-import-export');
            } else {
                // The user has specified a directory.
                drush_mkdir($directory);
                return $directory;
            }
        }
        // If a directory isn't specified, use the label argument or default sync directory.
        return \config_get_config_directory($label ?: CONFIG_SYNC_DIRECTORY);
    }

    /**
     * Returns the difference in configuration between active storage and target storage.
     */
    public function getChanges($target_storage)
    {
        /** @var \Drupal\Core\Config\StorageInterface $active_storage */
        $active_storage = \Drupal::service('config.storage');

        $config_comparer = new StorageComparer($active_storage, $target_storage, \Drupal::service('config.manager'));

        $change_list = array();
        if ($config_comparer->createChangelist()->hasChanges()) {
            foreach ($config_comparer->getAllCollectionNames() as $collection) {
                $change_list[$collection] = $config_comparer->getChangelist(null, $collection);
            }
        }
        return $change_list;
    }

    /**
     * Get storage corresponding to a configuration directory.
     */
    public function getStorage($directory)
    {
        if ($directory == \config_get_config_directory(CONFIG_SYNC_DIRECTORY)) {
            return \Drupal::service('config.storage.sync');
        } else {
            return new FileStorage($directory);
        }
    }

    /**
     * Build a table of config changes.
     *
     * @param array $config_changes
     *   An array of changes keyed by collection.
     */
    public static function configChangesTableFormat(array $config_changes, $use_color = false)
    {
        if (!$use_color) {
            $red = "%s";
            $yellow = "%s";
            $green = "%s";
        } else {
            $red = "\033[31;40m\033[1m%s\033[0m";
            $yellow = "\033[1;33;40m\033[1m%s\033[0m";
            $green = "\033[1;32;40m\033[1m%s\033[0m";
        }

        $rows = array();
        $rows[] = array('Collection', 'Config', 'Operation');
        foreach ($config_changes as $collection => $changes) {
            foreach ($changes as $change => $configs) {
                switch ($change) {
                    case 'delete':
                        $colour = $red;
                        break;
                    case 'update':
                        $colour = $yellow;
                        break;
                    case 'create':
                        $colour = $green;
                        break;
                    default:
                        $colour = "%s";
                        break;
                }
                foreach ($configs as $config) {
                    $rows[] = array(
                    $collection,
                    $config,
                    sprintf($colour, $change)
                    );
                }
            }
        }
        $tbl = _drush_format_table($rows);
        return $tbl;
    }

    /**
     * Print a table of config changes.
     *
     * @param array $config_changes
     *   An array of changes keyed by collection.
     */
    public static function configChangesTablePrint(array $config_changes)
    {
        $tbl =  self::configChangesTableFormat($config_changes, !drush_get_context('DRUSH_NOCOLOR'));

        $output = $tbl->getTable();
        if (!stristr(PHP_OS, 'WIN')) {
            $output = str_replace("\r\n", PHP_EOL, $output);
        }

        drush_print(rtrim($output));
        return $tbl;
    }

    /**
     * @hook interact @interact-config-name
     */
    public function interactConfigName($input, $output)
    {
        if (empty($input->getArgument('config_name'))) {
            $config_names = $this->getConfigFactory()->listAll();
            $choice = $this->io()->choice('Choose a configuration', drush_map_assoc($config_names));
            $input->setArgument('config_name', $choice);
        }
    }

    /**
     * @hook interact @interact-config-label
     */
    public function interactConfigLabel(InputInterface $input, ConsoleOutputInterface $output)
    {
        global $config_directories;

        $option_name = $input->hasOption('destination') ? 'destination' : 'source';
        if (empty($input->getArgument('label') && empty($input->getOption($option_name)))) {
            $choices = drush_map_assoc(array_keys($config_directories));
            unset($choices[CONFIG_ACTIVE_DIRECTORY]);
            if (count($choices) >= 2) {
                $label = $this->io()->choice('Choose a '. $option_name. '.', $choices);
                $input->setArgument('label', $label);
            }
        }
    }

    /**
     * Validate that a config name is valid.
     *
     * If the argument to be validated is not named $config_name, pass the
     * argument name as the value of the annotation.
     *
     * @hook validate @validate-config-name
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     * @return \Consolidation\AnnotatedCommand\CommandError|null
     */
    public function validateConfigName(CommandData $commandData)
    {
        $arg_name = $commandData->annotationData()->get('validate-config-name', null) ?: 'config_name';
        $config_name = $commandData->input()->getArgument($arg_name);
        $config = \Drupal::config($config_name);
        if ($config->isNew()) {
            $msg = dt('Config !name does not exist', array('!name' => $config_name));
            return new CommandError($msg);
        }
    }

    /**
     * Copies configuration objects from source storage to target storage.
     *
     * @param \Drupal\Core\Config\StorageInterface $source
     *   The source config storage service.
     * @param \Drupal\Core\Config\StorageInterface $destination
     *   The destination config storage service.
     */
    public static function copyConfig(StorageInterface $source, StorageInterface $destination)
    {
        // Make sure the source and destination are on the default collection.
        if ($source->getCollectionName() != StorageInterface::DEFAULT_COLLECTION) {
            $source = $source->createCollection(StorageInterface::DEFAULT_COLLECTION);
        }
        if ($destination->getCollectionName() != StorageInterface::DEFAULT_COLLECTION) {
            $destination = $destination->createCollection(StorageInterface::DEFAULT_COLLECTION);
        }

        // Export all the configuration.
        foreach ($source->listAll() as $name) {
            $destination->write($name, $source->read($name));
        }

        // Export configuration collections.
        foreach ($source->getAllCollectionNames() as $collection) {
            $source = $source->createCollection($collection);
            $destination = $destination->createCollection($collection);
            foreach ($source->listAll() as $name) {
                $destination->write($name, $source->read($name));
            }
        }
    }
}
