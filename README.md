# The Hawk
### *Audit & compliance made easy*

## What is this library
The Hawk library is meant to be a plug&play system for tracking and audit of database access by your application. It was
built from the ground up to be modular and extensible, with virtually any part being replaceable.

The library was primarily made to work with Symfony and Doctrine ORM. However, due to the modular design, it can be used
with any framework and any ORM.

## Implemented features
 - audit entities creation
 - audit entities update
 - audit entities deletion
 - audit entities association/disassociation
 - simple filtering support
   - include/exclude entities
   - include/exclude entities fields
 - domain-specificity
   - the application can decide, based on its domain knowledge, to exclude/include/modify any audit data before they're
     saved
   - support for custom data attached to each audit record
 - Doctrine ORM & Symfony integration for both sourcing and reading data


## Planned features
 - entity snapshotting, saving all data of the current entity
 - threshold filters
   - in come cases it's beneficial to only log some events which are touching a large sets of data
   - this allows for space conservation and automatic filtering of potential noise, especially for read/access events
   - an example case of this would be a session-aware filter which only starts logging access to CRM accounts when a 
     more than 100 accounts were accessed in an hour 
 - track `SELECT`/`UPDATE`/`DELETE` DQL operations
   - these operations are converted straight to SQL and thus no entities are touched
   - logging them, at least in a limited fashion, is possible but rather hard as Doctrine doesn't have any API for that
 - automatically exclude or mask [`SensitiveParameter`](https://www.php.net/manual/en/class.sensitiveparameter.php)
   fields
 - sending audit data to asynchronous queues with consistency provisioning
 - configuration with attributes
 - move entities mapping to XML to allow overriding (https://symfony.com/doc/current/bundles/best_practices.html#doctrine-entities-documents)


## Partially implemented features
 - entity read tracking
 - filtering different entities and operations based on the type of operation (create/update/delete) to allow scenarios
   like "only log user creation". This is implemented in the code - it just needs some clever configuration processing.

## Why?
This library was inspired by a great [`auditor-bundle 5.x` by Damien Harper](https://github.com/DamienHarper/auditor-bundle). 
While both bundles are similar in concept, they both diverge in the approach to audit:

=> UNFINISHED SECTION, listing advantages here
- read/access audit
- microsecond precision
- per-record instead of per-flush timestamping
- non-locking audit for highly concurrent systems
- storage as entities (vs. flat tables)
- no built-in viewer
- CLI elevation/sudo tracking
- inclusion/exclusion entities policies
- no annotations configuration (vs. DH has them)
- logging of binary fields
- no support for older environments
- has no cool logo :(
- split diff into separate fields for easier filtering
- [should :D] supports composite primary keys
- supports custom data types (verify DH doesn't! it doesn't seem link it as it has hardcoded list with convertToDatabaseValue)
- deleted entities logging => full data snapshot (vs. ID only in DH? it seems to only log metadata)
- different collection handling (separate association/disassociaton events in DH vs included in the main entity update)
   => DH does NOT log anything when you do collection->clear() and then flush()
   - option to initialize full collections on  
- logging any pauses in audit logging with reason and source (if desired via PauseAuditFilter)


In other words, this bundle isn't better - it addresses the problem from a different angle and with a different 
architectural compromises.


## FAQ
### Does it require Symfony?
While this repository contains formally a "Symfony Bundle", the library is written with decoupling in mind. While the
code includes automatic configuration for Symfony and Symfony-specific enhancement, it does not require any specific 
framework to operate. We've considered splitting the code into multiple repositories. However, we decided against that
to simplify development.

### Can it work with ORMs other than Doctrine?
Currently, the library contains support for Doctrine only. However, it is not dependent on Doctrine. It can audit any 
ORM or even be used without an ORM, given a correct producer is provided. The same applies to persistence of audit 
events: by default it stores them using a Doctrine entity, but any sink can be provided.

