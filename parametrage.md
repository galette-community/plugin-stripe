## Paramétrage du plugin Stripe

Allez sur la page _**"Préférences Stripe"**_.

Voici la liste des paramètres :

<table>
  <thead>
    <tr>
      <th>Nom</th>
      <th>Description</th>
      <th>Utilisation</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><tt>Clé publique Stripe</tt></td>
      <td>Clé publique de l'API Stripe</td>
      <td>Sert à échanger des données avec l'API Stripe (Javascript)</td>
    </tr>
    <tr>
      <td><tt>Clé secrète Stripe</tt></td>
      <td>Clé secrète de l'API Stripe</td>
      <td>Sert à échanger des données avec l'API Stripe (Back-Office)</td>
    </tr>
    <tr>
      <td><tt>Webhook Stripe</tt></td>
      <td>Pas un paramètre</td>
      <td>Affiche l'URL à configurer en webhook dans Stripe</td>
     </tr>
     <tr>
      <td><tt>Événements à configurer</tt></td>
      <td>Pas un paramètre</td>
      <td>Affiche les événements à configurer dans le webhook</td>
    </tr>
    <tr>
      <td><tt>Pays du compte Stripe</tt></td>
      <td>Pays dans lequel vous avez ouvert le compte Stripe à configurer</td>
      <td>Détermine les devises disponibles et les frais (intra ou extra européens)</td>
    </tr>
    <tr>
      <td><tt>Devis pour les paiements</tt></td>
      <td>Dans quelle devise le plugin doit facturer les paiements</td>
      <td>N'empêche pas de payer dans une autre devise, Stripe fait la conversion pour les cartes associés à des comptes dans d'autres devises. Certaines frais peuvent par contre s'appliquer dans ce cas.</td>
    </tr>
    <tr>
      <td><tt>Prix des contributions</tt></td>
      <td>Prix et activation des contributions pour le formulaire de paiement</td>
      <td>Détermine quelles sont les contributions disponibles dans le formulaire de paiement, et à quel prix. Le prix est un minimum, rien n'empêche l'adhérent de renseigner un chiffre supérieur. Les contributions marquées "Extension d'adhésion" dans les paramètres de Galette créent une cotisation pour le membre qui la paie.</td>
    </tr>
  </tbody>
</table>
