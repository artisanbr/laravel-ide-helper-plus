<?php

namespace Renalcio\LaravelIdeHelperPlus\Console;

use Composer\Autoload\ClassMapGenerator;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Barryvdh\LaravelIdeHelper\Console\ModelsCommand as ModelsCommandBase;


class ModelsCommand extends ModelsCommandBase
{

    //protected $filename = '_ide_helper_models.php';

    protected function generateDocs($loadModels, $ignore = '')
    {
        $output = "<?php

// @formatter:off
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */
\n\n";

        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');

        if (empty($loadModels)) {
            $models = $this->loadModels();
        } else {
            $models = [];
            foreach ($loadModels as $model) {
                $models = array_merge($models, explode(',', $model));
            }
        }

        $ignore = array_merge(
            explode(',', $ignore),
            $this->laravel['config']->get('ide-helper-plus.ignored_models', [])
        );

        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $this->comment("Ignoring model '$name'");
                }
                continue;
            }
            $this->properties = [];
            $this->methods = [];
            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new ReflectionClass($name);

                    //Verificar na lista de ingoradas
                    $class_dir = dirname($reflectionClass->getFileName());
                    $check_dir = Str::contains(Str::lower($class_dir), collect(config('ide-helper-plus.ignored_locations', []))->map(function($i, $k){
                        return Str::lower($i);
                    })->toArray());
                    if($check_dir){
                        $this->comment("Ignoring model '$name' - Ignored Location");
                        continue;
                    }

                    //Checkar se a classe da model está inclusa nas configuradas
                    $model_classes = config("ide-helper-plus.model_class");
                    $check_model = false;
                    foreach($model_classes as $model_class){
                        if ($reflectionClass->isSubclassOf($model_class)) {
                            $check_model = true;
                        }
                    }

                    if (!$check_model) {
                        $this->comment("Ignoring model '$name' - Not Subclass of: ".implode(', ', $model_classes));
                        continue;
                    }

                    $this->comment("Loading model '$name'", OutputInterface::VERBOSITY_VERBOSE);

                    if (!$reflectionClass->IsInstantiable()) {
                        // ignore abstract class or interface
                        continue;
                    }

                    $this->comment("Generating model '$name'");
                    /*$model = $this->laravel->make($name);*/
                    try {
                        $model = $this->laravel->make($name);
                    }catch (\Exception $e){
                        dump($e);
                        continue;
                    }

                    if ($hasDoctrine && $reflectionClass->hasMethod("getConnection")) {
                        $this->getPropertiesFromTable($model);
                    }else{
                        $this->getPropertiesFromAttributes($model);
                    }

                    if (method_exists($model, 'getCasts')) {
                        $this->castPropertiesType($model);
                    }

                    $this->getPropertiesFromMethods($model);
                    $this->getSoftDeleteMethods($model);
                    $this->getCollectionMethods($model);
                    $this->getFactoryMethods($model);

                    $this->runModelHooks($model);

                    $output                .= $this->createPhpDocs($name);
                    $ignore[]              = $name;
                    $this->nullableColumns = [];
                } catch (Throwable $e) {
                    $this->error('Exception: ' . $e->getMessage() .
                        "\nCould not analyze class $name.\n\nTrace:\n" .
                        $e->getTraceAsString());
                }
            }
        }

        if (!$hasDoctrine) {
            $this->error(
                'Warning: `"doctrine/dbal": "~2.3"` is required to load database information. ' .
                'Please require that in your composer.json and run `composer update`.'
            );
        }

        return $output;
    }

/*
    protected function loadModels()
    {
        $models = [];
        foreach ($this->dirs as $dir) {
            if (is_dir(base_path($dir))) {
                $dir = base_path($dir);
            }

            if (!is_dir($dir)) {
                $this->error("Cannot locate directory '{'$dir}'");
                continue;
            }

            $dirs = glob($dir, GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    $this->error("Cannot locate directory '{$dir}'");
                    continue;
                }

                if (file_exists($dir)) {
                    $classMap = ClassMapGenerator::createMap($dir);

                    // Sort list so it's stable across different environments
                    ksort($classMap);

                    foreach ($classMap as $model => $path) {
                        $models[] = $model;
                    }
                }
            }
        }
        return $models;
    }*/


    /**
     * Load the properties from model attributes.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromAttributes($model)
    {
        $fillables_model = $model->getFillable() ?? [];
        $attributes_model = $model->getAttributes();
        $fillables = [];

        foreach($fillables_model as $attribute => $default_value){
            $name = is_string($attribute) ? $attribute : $default_value;
            $value = is_string($attribute) ? $default_value : ($attributes_model[$name] ?? null);

            $fillables[$name] = $value;
        }

        $attributes = array_merge($fillables, $attributes_model);
        $casts = $model->getCasts();
        foreach ($attributes as $attribute => $default_value) {
            $name = is_string($attribute) ? $attribute : $default_value;
            $value = is_string($attribute) ? $default_value : null;
            if (in_array($name, $model->getDates())) {
                $type = $this->dateClass;
            } else {
                $type = $casts[$name] ?? gettype($default_value);
                switch ($type) {
                    case 'string':
                    case 'text':
                    case 'date':
                    case 'time':
                    case 'guid':
                    case 'datetimetz':
                    case 'datetime':
                    case 'decimal':
                        $type = 'string';
                        break;
                    case 'integer':
                    case 'bigint':
                    case 'smallint':
                        $type = 'integer';
                        break;
                    case 'boolean':
                        switch (config('database.default')) {
                            case 'sqlite':
                            case 'mysql':
                                $type = 'integer';
                                break;
                            default:
                                $type = 'boolean';
                                break;
                        }
                        break;
                    case 'float':
                        $type = 'float';
                        break;
                    case 'collection':
                        $type = '\Illuminate\Support\Collection';
                        break;
                    default:
                        $type = 'mixed';
                        break;
                }
            }

            $this->nullableColumns[$name] = true;

            $this->setProperty(
                $name,
                $this->getTypeInModel($model, $type),
                true,
                true,
                "",
                true
            );
            if ($this->write_model_magic_where) {
                $this->setMethod(
                    Str::camel('where_' . $name),
                    $this->getClassNameInDestinationFile($model, \Illuminate\Database\Eloquent\Builder::class)
                    . '|'
                    . $this->getClassNameInDestinationFile($model, get_class($model)),
                    ['$value']
                );
            }
        }
    }
}
