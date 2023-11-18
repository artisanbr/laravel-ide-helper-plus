<?php
/*
 * Copyright (c) 2023. Tanda Interativa - Todos os Direitos Reservados
 * Desenvolvido por Renalcio Carlos Jr.
 */

namespace ArtisanLabs\LaravelIdeHelperPlus\Console;

use Barryvdh\LaravelIdeHelper\Console\ModelsCommand as ModelsCommandBase;
use Composer\Autoload\ClassMapGenerator;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;


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

    public function castPropertiesType($model)
    {
        $casts = $model->getCasts();
        foreach ($casts as $name => $type) {
            if (Str::startsWith($type, 'decimal:')) {
                $type = 'decimal';
            } elseif (Str::startsWith($type, 'custom_datetime:')) {
                $type = 'date';
            } elseif (Str::startsWith($type, 'date:')) {
                $type = 'date';
            } elseif (Str::startsWith($type, 'datetime:')) {
                $type = 'date';
            } elseif (Str::startsWith($type, 'immutable_custom_datetime:')) {
                $type = 'immutable_date';
            } elseif (Str::startsWith($type, 'encrypted:')) {
                $type = Str::after($type, ':');
            }

            $params = [];

            switch ($type) {
                case 'encrypted':
                    $realType = 'mixed';
                    break;
                case 'boolean':
                case 'bool':
                    $realType = 'boolean';
                    break;
                case 'decimal':
                case 'string':
                    $realType = 'string';
                    break;
                case 'array':
                case 'json':
                    $realType = 'array';
                    break;
                case 'object':
                    $realType = 'object';
                    break;
                case 'int':
                case 'integer':
                case 'timestamp':
                    $realType = 'integer';
                    break;
                case 'real':
                case 'double':
                case 'float':
                    $realType = 'float';
                    break;
                case 'date':
                case 'datetime':
                    $realType = $this->dateClass;
                    break;
                case 'immutable_date':
                case 'immutable_datetime':
                    $realType = '\Carbon\CarbonImmutable';
                    break;
                case 'collection':
                    $realType = '\Illuminate\Support\Collection';
                    break;
                default:

                    if(Str::contains($type, ':')){

                        $casterClass = collect(explode(':', $type))->first();
                        $finalClass = collect(explode(':', $type))->last();

                        if(class_exists($casterClass) && class_exists($finalClass)){
                            $realType = $type;
                            break;
                        }

                    }

                    // In case of an optional custom cast parameter , only evaluate
                    // the `$type` until the `:`
                    $type = strtok($type, ':');
                    $realType = class_exists($type) ? ('\\' . $type) : ($type != 'NULL' ? $type : 'mixed');

                    if (!isset($this->properties[$name])) {
                        //dd($realType);
                        $this->setProperty($name, null, true, true);
                    }

                    $params = strtok(':');
                    $params = $params ? explode(',', $params) : [];

                    break;
            }

            if (!isset($this->properties[$name])) {
                continue;
            }
            if ($this->isInboundCast($realType)) {
                continue;
            }

            $realType = $this->checkForCastableCasts($realType, $params);
            $realType = $this->checkForCustomLaravelCasts($realType);
            $realType = $this->getTypeOverride($realType);
            $this->properties[$name]['type'] = $this->getTypeInModel($model, $realType);

            if(empty($this->properties[$name]['type'])){
                $this->properties[$name]['type'] = $this->getTypeInModel($model, $type);
            }

            if (isset($this->nullableColumns[$name])) {
                $this->properties[$name]['type'] .= '|null';
            }
        }
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

        /*if(!is_subclass_of($model, \Eloquent::class)){
            dd($casts);
        }*/

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

                        /*switch (true){
                            case Str::contains($type, ':'):
                                $type = collect(explode(':', $type))->last();
                                dd($type);
                                break;
                            default:
                                $type = 'mixed';
                                break;
                        }*/

                        if($type == 'NULL'){
                            $type = 'mixed';
                        }

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

            if ($this->write_model_magic_where && is_subclass_of($model, \Eloquent::class)) {
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

    protected function getTypeInModel(object $model,?string $type) : ?string{

        if(Str::contains($type, ':')){

            $casterClass = collect(explode(':', $type))->first();
            $finalClass = collect(explode(':', $type))->last();


            if(is_subclass_of($casterClass, AsCollection::class)){
                return "\\Illuminate\\Support\\Collection|\\{$finalClass}[]|\\Illuminate\\Support\\Collection<\\{$finalClass}>";
            }

            dd('Subcast', $casterClass, $finalClass, $type);


            //$type = collect(explode(':', $type))->last();
        }

        /*if(class_exists($type) && is_subclass_of($type, GenericModel::class)){
            dd($model, $type, parent::getTypeInModel($model, $type));
        }*/

        return parent::getTypeInModel($model, $type);
    }

    protected function createPhpDocs($class){

        $output = parent::createPhpDocs($class);

        if(!is_subclass_of($class, \Eloquent::class)){
            $extendClass = get_parent_class($class) ?? 'Eloquent';
            $output = Str::replace('extends \Eloquent', "extends \\{$extendClass}", $output);
        }

        return $output;
    }

    protected function getPropertiesFromAttributesOld($model)
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
