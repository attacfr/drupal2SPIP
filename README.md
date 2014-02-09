drupal2SPIP - Migration d’un site Drupal vers SPIP
==================================================

Ce script permet de migrer proprement un site Drupal vers SPIP. Il ne s’agit toutefois pas d’un script click'n'go, il est nécessaire de procéder à diverses adaptation du script en fonction de la configuration de votre instance de Drupal et de SPIP. Ce script a été utilisé pour migrer le site d’[Attac France](http://france.attac.org) de Drupal 6 vers SPIP.

Il s’agit d’un script réalisé pour un besoin particulier (migration du site d’Attac France), n’espérez pas convertir un site en lançant simplement le script… Il doit plutôt être utilisé comme une source d’inspiration pour permettre de faire votre propre script d’import.

## Fonctionnalités

Le script permet d’importer les *node* Drupal, de créer les auteurs, les mots-clés, de conserver (en partie) les URL des articles. Le code HTML est converti (dans la mesure du possible) dans la syntaxe SPIP.

Il est possible (moyennant adaptation du code) d’adapter le script au contexte du site Drupal. Il est possible de définir pour chaque type de *node* Drupal la manière de les importer dans le site SPIP (rubrique cible par exemple). Il est par ailleurs possible de définir des fonction de nettoyage / conversion du contenu pour l’adapter à SPIP.

## Installation

Le script `drupal2spip.php` doit être mis à la racine de votre site SPIP (à côté du script `spip.php` et du répertoire `ecrire/`. Le script fonctionne en mode CLI (« *command line interface* »), il sera donc nécessaire de disposer d’un accès *shell*. Le répertoire ``lib_tierce`` doit aussi être copié à la racine du site SPIP.

Une fois la migration terminée, il est possible de supprimer ces fichiers.

## Lancement de la procédure d’import

### Export des données de Drupal

Ce site prend en entrée un export Drupal au format « *serialize* » de [Node Export](https://drupal.org/project/node_export). Ces commandes pour [Drush](https://github.com/drush-ops/drush) devraient permettre d’exporter vos données dans le format attendu par `drupal2SPIP` :

```
  $ drush pm-download node_export
  $ drush pm-enable node_export node_export_serialize
  $ drush ne-export --format=serialize --file=/tmp/drupal_export.php
```

### Import des données dans SPIP

La commande magique à lancer est (mais ne vous attendez pas à ce que ça marche…) :
```
php drupal2spip.php -f /tmp/drupal_export.php
```
