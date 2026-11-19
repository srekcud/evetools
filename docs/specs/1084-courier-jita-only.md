# Spec #1084 : contrat de courrier depuis Jita seulement

Décision de Sylvain (2026-11-19) : Jita seul, ni liste de hubs, ni réglage.

## Règles

- Le devis de courrier ne compte que les achats dont l'origine résolue est Jita (`EveConstants::JITA_SOLAR_SYSTEM_ID`).
- Un achat d'une autre origine (hors système de l'atelier) n'entre ni dans le devis, ni dans les lignes de transport, ni dans le volume, le collatéral, la récompense ou les sauts.
- Un achat d'origine inconnue n'est jamais compté comme Jita (inchangé, `unknownOriginLines`).
- Un achat fait dans le système de l'atelier est ignoré (inchangé).
- Aucun achat Jita : devis `no_purchases`, origine `null`, aucun contrat à proposer.

## Réponse `GET /courier`

- `originSolarSystemId` / `originSolarSystemName` : Jita quand au moins un achat Jita est à transporter, `null` sinon.
- `otherOrigins` (devis par origine, #1022) est supprimé.
- `otherOriginLines` (nouveau) : achats hors Jita et hors atelier, une ligne par (système, type), quantités sommées, triées par id de système puis id de type. Champs : `solarSystemId`, `solarSystemName`, `typeId`, `typeName`, `quantity`. Sert de source à la liste « à amener au container » de #1085.
- Les candidats de contrat sont comparés au devis Jita.

## Front

`ProjectCourier.vue` : la section « autres lieux d'achat » disparaît, `CourierOriginQuote` aussi. Les achats hors Jita ne sont pas listés dans ce panneau, ils le seront par #1085.

## Tests

- Builder : achats tous hors Jita sans devis ; Jita + autre hub : devis Jita seul ; même type dans Jita et un hub ; origine inconnue inchangée ; `otherOriginLines` triées et sommées ; atelier = Jita.
- Mapper et API : `otherOriginLines` remplace `otherOrigins`.
