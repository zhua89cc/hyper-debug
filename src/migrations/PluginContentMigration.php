<?php
namespace verbb\hyper\migrations;

use verbb\hyper\fields\HyperField;

use Craft;
use craft\db\Query;
use craft\fields\Matrix;
use craft\helpers\App;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;

use verbb\supertable\fields\SuperTableField;

class PluginContentMigration extends PluginMigration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        App::maxPowerCaptain();

        // Because content migrations run separately to field migrations, the fields have already been migrated
        // to Hyper. So look for all Hyper fields and we can check if it has invalid or valid content.
        $this->fields = (new Query())
            ->from('{{%fields}}')
            ->where(['type' => HyperField::class])
            ->all();

        $fieldService = Craft::$app->getFields();

        // Update the field content
        $this->processFieldContent();

        $this->stdout('Finished Migration' . PHP_EOL, Console::FG_GREEN);

        return true;
    }

    /**
     * @throws \JsonException
     */
    public function processFieldContent(): void
    {
        foreach ($this->fields as $fieldData) {
            $this->stdout("Preparing to migrate field “{$fieldData['handle']}” ({$fieldData['uid']}) content.");

            // Fetch the field model because we'll need it later
            $field = Craft::$app->getFields()->getFieldById($fieldData['id']);

            $this->stdout("Field ID Using to Query “{$fieldData['id']}” ");

            $this->stdout("Field Instance “{$field}” ");

            if ($field) {
                $column = ElementHelper::fieldColumn($field->columnPrefix, $field->handle, $field->columnSuffix);
                $this->stdout("Column is  “{$column}” ");

                $this->stdout("Field Context “{$field->context}” ");


                // Handle global field content
                if ($field->context === 'global') {
                    $this->stdout("Field is Global");

                    $content = (new Query())
                        ->select([$column, 'id', 'elementId'])
                        ->from('{{%content}}')
                        ->where(['not', [$column => null]])
                        ->andWhere(['not', [$column => '']])
                        ->all();

                    echo json_encode($content, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                    foreach ($content as $row) {
                        $settings = $this->convertModel($field, Json::decode($row[$column]));

                        if ($settings) {
                            Db::update('{{%content}}', [$column => Json::encode($settings)], ['id' => $row['id']], [], true, $this->db);

                            $this->stdout('    > Migrated content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_GREEN);
                        } else {
                            // Null model is okay, that's just an empty field content
                            if ($settings !== null) {
                                $this->stdout('    > Unable to convert content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_RED);
                            }
                        }
                    }
                }

                // Handle Matrix field content
                if (str_contains($field->context, 'matrixBlockType')) {

                    $this->stdout("Field is Matrix");

                    // Get the Matrix field, and the content table
                    $blockTypeUid = explode(':', $field->context)[1];

                    $matrixInfo = (new Query())
                        ->select(['fieldId', 'handle'])
                        ->from('{{%matrixblocktypes}}')
                        ->where(['uid' => $blockTypeUid])
                        ->one();

                    //$this->stdout("Matrix Content is “{$matrixInfo}” ");
                    echo json_encode($matrixInfo, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                    if ($matrixInfo) {
                        $matrixFieldId = $matrixInfo['fieldId'];
                        $matrixBlockTypeHandle = $matrixInfo['handle'];

                        $matrixField = Craft::$app->getFields()->getFieldById($matrixFieldId);
                        $this->stdout("We have Matrix Info Below");
                        echo json_encode($matrixField, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                        if ($matrixField && $matrixField instanceof Matrix) {
                            $column = ElementHelper::fieldColumn($field->columnPrefix, $matrixBlockTypeHandle . '_' . $field->handle, $field->columnSuffix);

                            $content = (new Query())
                                ->select([$column, 'id', 'elementId'])
                                ->from($matrixField->contentTable)
                                ->where(['not', [$column => null]])
                                ->andWhere(['not', [$column => '']])
                                ->all();
                            $this->stdout("We have Matrix Content Below");

                            echo json_encode($content, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);


                            foreach ($content as $row) {
                                $settings = $this->convertModel($field, Json::decode($row[$column]));
                                
                                if ($settings) {
                                    Db::update($matrixField->contentTable, [$column => Json::encode($settings)], ['id' => $row['id']], [], true, $this->db);
                                
                                    $this->stdout('    > Migrated “' . $field->handle . ':' . $matrixBlockTypeHandle . '” Matrix content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_GREEN);
                                } else {
                                    // Null model is okay, that's just an empty field content
                                    if ($settings !== null) {
                                        $this->stdout('    > Unable to convert Matrix content “' . $field->handle . ':' . $matrixBlockTypeHandle . '” #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_RED);
                                    }
                                }
                            }
                        }
                    }
                }

                // Handle Super Table field content
                if (str_contains($field->context, 'superTableBlockType')) {
                    // Get the Super Table field, and the content table
                    $blockTypeUid = explode(':', $field->context)[1];
                    $this->stdout("Field is Supertable");

                    $superTableFieldId = (new Query())
                        ->select(['fieldId'])
                        ->from('{{%supertableblocktypes}}')
                        ->where(['uid' => $blockTypeUid])
                        ->scalar();

                    $superTableField = Craft::$app->getFields()->getFieldById($superTableFieldId);

                    if ($superTableField && $superTableField instanceof SuperTableField) {
                        $column = ElementHelper::fieldColumn($field->columnPrefix, $field->handle, $field->columnSuffix);

                        $content = (new Query())
                            ->select([$column, 'id', 'elementId'])
                            ->from($superTableField->contentTable)
                            ->where(['not', [$column => null]])
                            ->andWhere(['not', [$column => '']])
                            ->all();

                        foreach ($content as $row) {
                            $settings = $this->convertModel($field, Json::decode($row[$column]));

                            if ($settings) {
                                Db::update($superTableField->contentTable, [$column => Json::encode($settings)], ['id' => $row['id']], [], true, $this->db);
                            
                                $this->stdout('    > Migrated “' . $field->handle . '” Super Table content #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_GREEN);
                            } else {
                                // Null model is okay, that's just an empty field content
                                if ($settings !== null) {
                                    $this->stdout('    > Unable to convert Super Table content “' . $field->handle . '” #' . $row['id'] . ' for element #' . $row['elementId'], Console::FG_RED);
                                }
                            }
                        }
                    }
                }
            }

            // Check for Vizy fields, a little different
            if ($this->isPluginInstalledAndEnabled('vizy')) {
                $this->migrateVizyContent($fieldData);
            }

            $this->stdout("    > Field “{$field['handle']}” content migrated." . PHP_EOL, Console::FG_GREEN);
        }
    }
}
