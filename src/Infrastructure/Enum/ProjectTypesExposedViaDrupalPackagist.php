<?php

declare(strict_types=1);

namespace mxr576\ddqg\Infrastructure\Enum;

/**
 * @internal
 *
 * @see https://git.drupalcode.org/project/project_composer/-/blob/f298ce484fbd5c5c609c99d1e805bb0e47c3e17a/project_composer.module#L58
 *
 * @todo Restructure namespaces, introduce a top DrupalOrg namespace an move this and DrupalOrgApi and UpdateStatusApi
 *   under it.
 */
enum ProjectTypesExposedViaDrupalPackagist: string
{
    case MODULE = 'project_module';

    case THEME = 'project_theme';

    case DRUPAL_CORE = 'project_core';
}
