# [Upmind Provision Providers](https://github.com/upmind-automation) - Servers

[![Latest Version on Packagist](https://img.shields.io/packagist/v/upmind/provision-provider-servers.svg?style=flat-square)](https://packagist.org/packages/upmind/provision-provider-servers)

This provision category contains the common functions used in provisioning flows for servers on various popular cloud platforms.

- [Installation](#installation)
- [Usage](#usage)
  - [Quick-start](#quick-start)
- [Supported Providers](#supported-providers)
- [Functions](#functions)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)
- [Upmind](#upmind)

## Installation

```bash
composer require upmind/provision-provider-servers
```

## Usage

This library makes use of [upmind/provision-provider-base](https://packagist.org/packages/upmind/provision-provider-base) primitives which we suggest you familiarize yourself with by reading the usage section in the README.

### Quick-start

The easiest way to see this provision category in action and to develop/test changes is to install it in [upmind/provision-workbench](https://github.com/upmind-automation/provision-workbench#readme).

Alternatively you can start using it for your business immediately with [Upmind.com](https://upmind.com/start) - the ultimate web hosting billing and management solution.

**If you wish to develop a new Provider, please refer to the [WORKFLOW](WORKFLOW.md) guide.**

## Supported Providers

The following providers are currently implemented:
  - [Linode](https://www.linode.com/docs/api/)
  - [SolusVM v1](https://docs.solusvm.com/v1/api/API%2Boverview.html)
  - [Virtualizor](https://www.virtualizor.com/docs/admin-api/virtual-servers/)
  - [Virtuozzo Hybrid Server 7](https://docs.virtuozzo.com/legacy/va61/Virtuozzo_Automator_Agent_XML_API_Reference.pdf)
  - [OnApp](https://docs.onapp.com/apim/6.0)
  - [Virtfusion](https://docs.virtfusion.com/api/)

## Functions

| Function | Parameters | Return Data | Description |
|---|---|---|---|
| create() | [_CreateParams_](src/Data/CreateParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Create and boot a new server |
| getInfo() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Get information about a server such as its label, current state (running/rebooting etc), image (e.g., ubuntu), size and region |
| getConnection() | [_GetConnectionParams_](src/Data/GetConnectionParams.php) | [_ConnectionResult_](src/Data/ConnectionResult.php) | Get a command to connect to a server |
| changeRootPassword() | [_ChangeRootPasswordParams_](src/Data/ChangeRootPasswordParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Update the root password used to SSH into a server |
| resize() | [_ResizeParams_](src/Data/ResizeParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Redeploy an existing server with a different resource allocation |
| reinstall() | [_ReinstallParams_](src/Data/ReinstallParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Reinstall (wipe/reset) an existing server server using a particular image |
| reboot() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Reboot (shutdown then power-on) a running server |
| shutdown() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Shut down a running server |
| powerOn() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Boot a powered-off server |
| suspend() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Suspend a server |
| unsuspend() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Unsuspend a server |
| attachRecoveryIso() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Attach recovery ISO |
| detachRecoveryIso() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_ServerInfoResult_](src/Data/ServerInfoResult.php) | Detach recovery ISO |
| terminate() | [_ServerIdentifierParams_](src/Data/ServerIdentifierParams.php) | [_EmptyResult_](src/Data/EmptyResult.php) | Terminate (delete) an existing server |

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

 - [Harry Lewis](https://github.com/uphlewis)
 - [Codeline](https://github.com/CodelineNL)
 - [All Contributors](../../contributors)

## License

GNU General Public License version 3 (GPLv3). Please see [License File](LICENSE.md) for more information.

## Upmind

Sell, manage and support web hosting, domain names, ssl certificates, website builders and more with [Upmind.com](https://upmind.com/start)
