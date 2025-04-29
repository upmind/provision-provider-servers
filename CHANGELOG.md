# Changelog

All notable changes to the package will be documented in this file.

## [v4.0.7](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v4.0.7) - 2025-04-29

- Update getConnection params and result data to support application login + form post results
- Implement cpanel/whm form post login in Virtualizor

## [v4.0.6](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v4.0.6) - 2025-02-19

- Implement Virtualizor SSO

## [v4.0.5](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v4.0.5) - 2024-12-23

- Fix Virtfusion/ApiClient::getServerInfo() for unbuilt servers with empty name/hostname
- Fix Virtfusion/ApiClient::buildServer() to always pass a valid hostname

## [v4.0.4](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v4.0.4) - 2024-12-13

- Fix type error in Virtfusion ApiClient::create()

## [v4.0.3](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v4.0.3) - 2024-11-30

- Add `customer_name` and `upmind_client_int_id` to CreateParams
- Implement Virtfusion provider

## [v4.0.2](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v4.0.2) - 2024-08-01

- Update OnApp/ApiClient::getHypervisorLocation() fix 404 api error when hypervisor_group has no location set
- Update Virtualizor::getConnection() throw error result if no IP address assigned

## [v4.0.1](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v4.0.1) - 2024-05-16

- Implement Virtualizor suspension and unsuspension
- Fix Virtualizor API redirect response and error handling

## [v4.0.0](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v4.0.0) - 2024-04-15

- Rename `getSshConnectionCommand()` to `getConnection()`
  - Add `type` to result data to determine connection type returned values
  - Add `redirect_url` for when `type` is "redirect"
  - Add `vnc_connection` for when `type` is "vnc"
- Add `suspend()` and `unsuspend()` functions
  - Add `suspended` flag to ServerInfoResult
- Add `attachRecoveryIso()` and `detachRecoveryIso()` functions
- Update CreateParams
  - Add `software` array
  - Add `licenses` array
  - Add `metadata` array
- Update ServerInfoResult
  - Add `suspended` bool
  - Add `software` array
  - Add `licenses` array
  - Add `metadata` array
- Loosen `ip_address` validation in ServerInfoResult

## [v3.2.0](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.2.0) - 2024-03-01

- Add setters for new ServerInfoResult properties `disk_mb`, `memory_mb` and `cpu_cores`

## [v3.1.2](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.1.2) - 2024-01-24

- Fix undefined variable error after unknown exception in Virtuozzo getInfo()

## [v3.1.1](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.1.1) - 2023-12-05

- Update OnApp API error handling; tweak result message and error data

## [v3.1.0](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.1.0) - 2023-10-20

- Implement OnApp provider

## [v3.0.4](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.0.4) - 2023-10-17

- Fix SolusVM findNodeGroupId() return type when using node group id

## [v3.0.3](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.0.3) - 2023-10-16

- Update SolusVM ApiClient return response_body if responseData is empty
- Toggle SolusVM virtualization_type parameter to lowercase

## [v3.0.2](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.0.2) - 2023-10-12

- Fix Linode error handling sprintf() error

## [v3.0.1](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.0.1) - 2023-10-12

- Update Linode, SolusVM nd Virtualizor to require size parameter

## [v3.0.0](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v3.0.0) - 2023-10-12

- Update CreateParams, ResizeParams and ServerInfoResult add `disk_mb`, `memory_mb` + `cpu_cores` values as an alternative to `size`
- Implement Virtuozzo provider

## [v2.3.3](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.3.3) - 2023-06-28

- Fix Virtualizor reinstall() for VPSes not hosted on the default server/node

## [v2.3.2](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.3.2) - 2023-06-15

- Fix SolusVM ApiClient listTemplates() error for 'invalid' virtualization types
  - E.g., VMs that return a virtualization type `"xenhvm"` but this endpoint expects `"xen hvm"`

## [v2.3.1](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.3.1) - 2023-06-09

- Fix Virtualizor create() for location_type=geographic configurations

## [v2.3](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.3) - 2023-06-09

- Increase Virtualizor API request timeout to 60
- Add Server Group location type to Virtualizor provision configurations

## [v2.2](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.2) - 2023-06-09

- Add `ignore_ssl_errors` boolean to Virtualizor configuration
- Update Virtualizor ApiClient::changeVirtualServerRootPass() loosen response check
- Tweak Virtualizor ApiClient connect + request timeouts and add error handling

## [v2.1.4](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.1.4) - 2023-06-07

- Set SolusVM API client connect_timeout to 5 seconds
- Set SolusVM API client timeout to 10 seconds

## [v2.1.3](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.1.3) - 2023-06-05

- Fix SolusVM getServerInfoResult() location for suspended instances

## [v2.1.2](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.1.2) - 2023-06-05

- Fix empty location error in SolusVM getServerInfoResult()

## [v2.1.0](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.1.0) - 2023-06-02

- Add `node` to ServerInfoResult
- Make `ip_address` nullable in ServerInfoResult
- Update SolusVM ServerInfoResult, set `location` according to node location and
  set `node` as the node name
- Implement Virtualizor provider

## [v2.0.1](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.0.1) - 2023-05-18

- Update SolusVM getServerInfoResult() fix undefined index errors for suspended
  servers

## [v2.0](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v2.0) - 2023-04-26

- Add getSshConnectionCommand() function
- Add `virtualization_type`, `customer_identifier`, `email` parameters to CreateParams
  and return values to ServerInfoResult
- Loosen ServerInfoResult `hostname` return value validation to allow non-domain
  hostnames
- Add SolusVM provider

## [v1.0.2](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v1.0.2) - 2023-03-01

- Fix Linode findImage() and findType() id regexes

## [v1.0.1](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v1.0.1) - 2023-01-13

- Reduce min upmind/provision-provider-base version constraint to 3.4

## [v1.0](https://github.com/upmind-automation/provision-provider-servers/releases/tag/v1.0) - 2023-01-13

Initial public release
