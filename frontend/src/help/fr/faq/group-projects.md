# FAQ : projets de groupe

## Qui peut rejoindre un projet, qui approuve, et que veut dire « approuvé » ?

**Rejoindre.** On rejoint un projet avec son lien d'invitation, tant que le projet n'est pas privé et qu'il est au statut publié ou en cours. Un projet privé répond comme si le lien n'existait pas.

- Si votre corporation est celle du propriétaire, vous êtes accepté tout de suite.
- Sinon, votre demande reste **en attente** jusqu'à ce que le propriétaire ou un admin l'accepte.
- Seul le propriétaire peut nommer un admin.
- Un membre retiré, ou parti de lui-même, ne revient que par une nouvelle demande en attente.

**Approuver.** Chaque contribution (matériaux, installation de job, BPC, location de ligne) est soumise à approbation : en attente, approuvée ou rejetée. Le propriétaire et les admins relisent, mais personne ne relit sa propre contribution, sauf le propriétaire quand il est le seul relecteur possible. Dans un projet solo, vos contributions sont donc approuvées dès le dépôt.

**Approuvé = figé, sauf correction avant la vente.** Une contribution approuvée ne diminue plus. Exceptions : une contribution que vous avez déclarée en doublon d'une contribution détectée automatiquement, que les relecteurs peuvent rejeter, et une contribution de matériau d'une ligne de matériau, qu'un propriétaire ou administrateur peut rouvrir (elle repasse en attente) tant que le projet n'est pas en phase de vente. Quand le projet passe à **Terminé**, tous les comptes sont figés : plus de contribution, de revue, de vente ni de changement de frais.

## Comment un apport est-il valorisé ?

La valeur d'un apport est :

- le **prix payé**, pour un achat lié au projet ;
- le **coût ESI**, pour un job lié au projet (un apport par participant qui a lancé le job) ;
- le **prix Jita du jour de l'apport** (percentile 5 %), pour un matériau déclaré sans achat lié.

Quand le prix est inconnu, la valeur de l'apport est inconnue, jamais 0. Voir aussi [Des chiffres qui semblent faux](/help?page=faq/numbers-that-look-wrong).

## Comment le revenu est-il réparti, et quand les payouts sont-ils exacts ?

Le propriétaire ou un admin enregistre les ventes du projet. Le calcul se fait ainsi :

1. **Revenu net** = revenu des ventes − frais de courtage − taxe de vente − frais des contrats. Une vente par contrat ne paie ni courtage ni taxe de vente ; seuls les frais réels du contrat comptent.
2. **Marge** = (revenu net − coût total des contributions approuvées) ÷ coût total.
3. **Part de profit d'un membre** = ses coûts apportés × la marge.
4. **Payout** = ses coûts apportés + sa part de profit, soit `coûts apportés × (1 + marge)`.

Tant que tout n'est pas vendu, la marge affichée est provisoire : les invendus sont valorisés au prix de la vente la plus récente.

Les payouts sont **inconnus** dans trois cas : aucune vente enregistrée, coût total des apports égal à 0, ou frais ou coûts inconnus. Ils sont exacts quand tous les produits sont vendus et que les frais et les valeurs d'apport sont connus.

Les virements en jeu sont calculés à ce moment-là : chaque vendeur garde le revenu net de ses ventes, et le plus petit nombre de virements solde les écarts. Mettez la référence de paiement du projet (`IP-` suivi de 6 caractères) en motif de chaque virement. Le destinataire coche « reçu » de son côté.
