# PHP 8.x Compatibility Checker

This little command line tool will output the current state of PHP 8.x Compatibility for a given project to quickly check whether you can upgrade a Magento installation to PHP 8.1 (which is supported from Magento 2.4.4).

## Requirements
- PHP 8.1
- A [Private Packagist](https://www.packagist.com) account
- Making use of subrepositories in Private Packagist

## Configuration
Set the Composer keys and Packagist API keys in the `.env` file. 

## Usage
```
Description:
  Check packages against PHP version

Usage:
  check [options] [--] <organization> <subrepo>

Arguments:
  organization                   
  subrepo                        

Options:
      --phpversion[=PHPVERSION]   [default: "8.1"]
      --lockfile[=LOCKFILE]
      --only-direct (only test direct packages, not dependencies of packages)
      --force (overwrite results file)
```

Example; 

```
./pcc check <your-organization> <subrepository-name> --lockfile ../../path/to/magento2/composer.lock --only-direct
```

## Example output

```
 -------------------------------------- -------------- ---------------------------------------------------------- --------------- 
  Package                                Status         Constraint                                                 Final result   
 -------------------------------------- -------------- ---------------------------------------------------------- --------------- 
  faonni/module-breadcrumbs              Unknown                                                                   OK             
  mageplaza/module-delete-orders         Unknown                                                                   OK             
  dealer4dealer/xcore-magento2           Unknown                                                                   OK             
  degdigital/magento2-customreports      Unknown                                                                   OK             
  justbetter/magento2-sentry             Risky          >=7.0                                                      OK             
  magento2translations/language_nl_nl    Unknown                                                                   Unknown error  
  elgentos/module-lightspeed             Risky          >=7.2                                                      OK             
  elgentos/frontend2fa                   Unknown                                                                   OK             
  mollie/magento2                        Risky          >=7.1                                                      OK             
  elgentos/magento2-cicd                 Unknown                                                                   OK             
  boldcommerce/magento2-ordercomments    Incompatible   ~7.0.0|~7.1.0|~7.2.0|~7.3.0|~7.4.0                         Incompatible   
  fooman/pdfcustomiser-m2                Unknown                                                                   Unknown error  
  juashyam/authenticator                 Incompatible   ~5.5.0|~5.6.0|~7.0.0|~7.1.0|~7.2.0|~7.3.0                  Incompatible   
  mageplaza/module-customer-approval     Unknown                                                                   OK             
  msp/adminrestriction                   Incompatible   ^7.0|^7.1                                                  Incompatible   
  avstudnitz/scopehint2                  Unknown                                                                   OK             
  mageplaza/module-smtp                  Unknown                                                                   OK             
  fisheye/module-url-rewrite-optimiser   Unknown                                                                   OK             
  smile/elasticsuite                     Unknown                                                                   OK             
  vaimo/composer-patches                 Risky          >=7.0.0                                                    OK             
  elgentos/issue-templates               Unknown                                                                   OK             
  ethanyehuda/magento2-cronjobmanager    Compatible     ~7.1.0 || ~7.2.0 || ~7.3.0 || ~7.4.0 || ~8.0.0 || ~8.1.0   OK             
  splendidinternet/mage2-locale-de-de    Unknown                                                                   OK             
 -------------------------------------- -------------- ---------------------------------------------------------- --------------- 
Summary for subrepository-name: Compatible: 18 / 23 (78%)
```

## How to read the output
If under Status it says "Compatible" then the extension has already been declared compatible by the developer itself.

If under Status it says "Risky" or "Unknown" it could be that the extension is PHP 8.1 compatible but the developer hasn't declared it as such yet. 

In the case of Risky/Unknown we run a PHPCompatibility check for PHP 8.1 using PHPCodeSniffer. The result is then shown in the last column ("Final result").

If the Final result column says "OK" then the extension is code-technically 8.1 compatible but the composer.json has not (yet) been updated. In that case you can install the extension by adding --ignore-platform-req php to composer require/update/install. Immediately create a pull request in the corresponding repo or email the developer if it is a paid extension.

If the Final result column says "Incompatible" there will be actual work to be done. See if you can fix it yourself and do a PR. Maybe there is an alternative extension available that does the same thing but is PHP 8.1 compatible?

If the Final result column says "Unknown error" or "General error" in the last column you will have to manually check PHP 8.1 compatibility - the automatic tool has then not been able to do it for some reason. Sometimes it is a metapackage - the tool can't check if it is compatible, and then it gives a "General error".

Note; if the percentage is not 100% that doesn't mean you can't upgrade!

