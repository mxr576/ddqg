Drupal Dependency Quality Gate (DDQG)
---

This project aims to help run Drupal projects on secure and high-quality Drupal dependencies.

**CHECK OUT** the [mxr576/ddqg-composer-audit](https://packagist.org/packages/mxr576/ddqg-composer-audit) package that
extends `composer audit` command with advisories originating from the `^dev-no-no-[a-zA-Z]+-versions$` releases.

## Releases

Releases of this package that matches the `^dev-no-no-[a-zA-Z]+-versions$` regex ensure that your project
doesn't have installed dependencies with known quality problems.

<img alt="Family Guy, Consuela says: No, no, no low quality dependencies" height="250" src="https://i.imgflip.com/7ijrpx.jpg"/>

```shell
$ composer require --dev mxr576/ddqg:[dev-no-no-insecure-versions|dev-no-unsupported-versions|dev-non-d10-compatible-versions]
```

* `dev-no-no-insecure-versions`: Project releases (versions) affected by public security advisories (PSAs), only
  in currently _supported branches_ of a project.
* `dev-no-no-unsupported-versions`: This was inspired by [this thread](https://github.com/drupal-composer/drupal-security-advisories/issues/29)
  and it is a list of:
  * Unsupported (abandoned) projects by maintainers
  * Unsupported project releases (versions) by maintainers
  * Project releases that are not [covered by the Drupal Security Team](https://www.drupal.org/node/475848)
* `dev-non-d10-compatible-versions`: For Drupal 9 projects only, prevents installation of package versions that are not
  Drupal 10 compatible. It can make the Drupal 10 upgrade more painless.
  * **Warning**: This is only ~99% accurate because core compatibility information sometimes cannot be identified
    from the information available on [Update Status API](https://www.drupal.org/drupalorg/docs/apis/update-status-xml).
compatible. See Github Actions logs for skipped projects/versions.
* [PLANNED] An opinionated list of projects that should be avoided

**Should you depend on both `dev-no-no-insecure-versions` and `dev-no-no-unsupported-versions` and at the same time?**

YES, you should. The `dev-no-no-insecure-versions` only contains version ranges affected by a PSA if they are in a
supported branch by maintainers. When a branch becomes unsupported, related version ranges disappear from this list.
The reasoning behind this implementation is that if a branch is not supported by maintainers (neither covered Drupal
Security Team) then your biggest problem is not depending on a version that has known PSA (which may or may not be
leveraged on your project) but the fact that your project depends on an unsupported version.

## TODOs

* Ignore releases with Drupal 7 compatibility as there is no plan to support Drupal 7
