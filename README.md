
# LicenseGate WHMCS
An WHMCS Modules For Selling Software License Integrated With LicenseGate.

## How To Install
1. Navigate To `WHMCS-ROOT/modules/servers/` and upload the `licensegate` folder there
```
WHMCS-Root/
    └── modules/
        └── servers/
            └── licensegate/
                ├── licensegate.php
                └── whmcs.json
```
2. Navigate to `Your WHMCS Sites > System Settings > Servers` and Create New Servers
3. Fill The Configuration & Save Your Changes
```
hostname: YOUR_LICENSEGATE_BACKEND_DOMAIN
password: YOUR_LICENSEGATE_USER_API_KEY
```
4. Navigate To `Your WHMCS Sites > System Settings > Servers` and Create New Groups
5. Then Choose the created server and press the Add button
6. Navigate to `Your WHMCS Sites > System Settings > Products/Services > Products/Services`
7. Create your Product with the type of Other, Fill the configuration & save it
8. Navigate `Module` tab on your Product, choose for Module Name `LicenseGate` and for the Server Group the group you created in step 5
9. The Product now is ready to use

## Authors
- [@nekomonci12](https://www.github.com/nekomonci12) (Module Creator)
- [@DevLeoko](https://github.com/DevLeoko) [(LicenseGate Creator)](https://github.com/DevLeoko/license-gate)
