# What is Sendent Synchronizer for Nextcloud
Easy to use synchronization configurtion management using groups.

# Installation
The easiest way to install this app is by using the [Nextcloud app store](https://apps.nextcloud.com/apps/sendent). If you like to build from source, please continue reading. For the installation you need node, yarn, php and composer.

Clone this repo into your nextcloud app directory, or [download it as zip](https://github.com/Sendent-B-V/Sendent-App-for-Nextcloud/archive/refs/heads/master.zip) and extract it there, and change into the new directory:

```console
$ git clone https://github.com/Sendent-B-V/Sendent-App-for-Nextcloud YOUR_NEXTCLOUD_ROOT/apps/sendent

$ cd YOUR_NEXTCLOUD_ROOT/apps/sendent
```

Next install all dependencies and create a build (if you have make, execute `make build` as a shortcut):

```console
$ composer install
$ yarn install
$ yarn build
```

Now you should be able to enable this app on your Nextcloud app page.

# Questions?
If you have any questions, please contact us at: support@sendent.nl
