<?php
declare(strict_types=1);

/**
 * Seed the players table with the official WK2026 squads (ESPN.nl, 2026).
 *
 *   php migrations/seed_players.php          # add players, skip duplicates
 *   php migrations/seed_players.php --reset  # wipe players table first
 *
 * Teams not yet announced (Tsjechië, Mexico, Canada, …) are skipped.
 * Add them later via /admin/players.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

$reset = in_array('--reset', $argv, true);

if ($reset) {
    echo "→ Wipen van bestaande spelers…\n";
    // First detach any form.topscorer_player_id that points to a player we're about to drop
    Database::query('UPDATE forms SET topscorer_player_id = NULL WHERE topscorer_player_id IS NOT NULL');
    Database::query('DELETE FROM players');
}

$squads = [
    // ----- Group A -----
    'Zuid-Afrika' => [
        'Ronwen Williams','Ricardo Goss','Sipho Chaine','Khuliso Mudau','Nkosinathi Sibisi','Ime Okon',
        'Khulumani Ndamane','Aubrey Modiba','Samukele Kabini','Thabang Matuludi','Olwethu Makhanya',
        'Kamogelo Sebelebele','Bradley Cross','Mbekezeli Mbokazi','Teboho Mokoena','Thalente Mbatha',
        'Sphephelo Sithole','Jayden Adams','Oswin Appollis','Iqraam Rayners','Tshepang Moremi',
        'Relebohile Mofokeng','Evidence Makgopa','Themba Zwane','Lyle Foster','Thapelo Maseko',
    ],
    'Zuid-Korea' => [
        'Seung-Gyu Kim','Bum-keun Song','Hyeon-woo Jo','Moon-hwan Kim','Min-jae Kim','Tae-hyeon Kim',
        'Jin-seob Park','Young-woo Seol','Jens Castrop','Ki-hyuk Lee','Tae-seok Lee','Han-beom Lee',
        'Yu-min Cho','Jin-gyu Kim','Jun-ho Bae','Seung-ho Paik','Hyun-jun Yang','Ji-sung Eom',
        'Kang-in Lee','Dong-gyeong Lee','Jae-sung Lee','In-beom Hwang','Hee-chan Hwang','Heung-min Son',
        'Hyeon-gyu Oh','Gue-sung Cho',
    ],
    // ----- Group B -----
    'Bosnië en Herzegovina' => [
        'Nikola Vasilj','Martin Zlomislić','Osman Hadžikić','Sead Kolašinac','Amar Dedić','Nihad Mujakić',
        'Nikola Katić','Tarik Muharemović','Stjepan Radeljić','Dennis Hadžikadunić','Nidal Čelik',
        'Amir Hadžiahmetović','Ivan Šunjić','Ivan Bašić','Dženis Burnić','Ermin Mahmić','Benjamin Tahirović',
        'Amar Memić','Armin Gigović','Kerim Alajbegović','Esmir Bajraktarević','Ermedin Demirović',
        'Jovo Lukić','Samed Baždar','Haris Tabaković','Edin Džeko',
    ],
    // ----- Group C -----
    'Brazilië' => [
        'Alisson Becker','Ederson','Weverton','Alex Sandro','Bremer','Danilo','Douglas Santos',
        'Gabriel Magalhães','Leo Pereira','Marquinhos','Roger Ibañez','Wesley','Bruno Guimarães',
        'Casemiro','Fabinho','Lucas Paqueta','Endrick','Gabriel Martinelli','Igor Thiago',
        'Luiz Henrique','Matheus Cunha','Neymar','Raphinha','Rayan','Vinicius Junior',
    ],
    'Marokko' => [
        'Mounir El Kajoui','Yassine Bounou','Ahmed Reda Tagnaouti','Achraf Hakimi','Nayef Aguerd',
        'Issa Diop','Anass Salah-Eddine','Chadi Riad','Redouane Halhal','Zakaria El Ouahdi',
        'Youssef Belammari','Noussair Mazraoui','Azzeddine Ounahi','Bilal El Khannouss','Ismael Saibari',
        'Ayyoub Bouaddi','Sofyan Amrabat','Neil El Aynaoui','Samir El Mourabet','Brahim Díaz',
        'Abdessamad Ezzalzouli','Yassine Gessime','Chemsdine Talbi','Soufiane Rahimi','Ayoub El Kaabi',
        'Ayoube Amaimouni',
    ],
    'Haïti' => [
        'Johny Placide','Alexandre Pierre','Josué Duverger','Carlens Arcus','Wilguens Paugain',
        'Ricardo Adé','Jean Kévin Duverne','Hannes Delcroix','Keeto Thermoncy','Martin Expérience',
        'Duke Lacroix','Josué Casimir','Leverton Pierre','Dominique Simon','Woodensky Pierre',
        'Carl Fred Sainté','Danley Jean-Jacques','Jean Ricner Bellegarde','Duckens Nazon',
        'Frantzdy Pierrot','Deedson Louicius','Ruben Providence','Yassin Fortuné','Wilson Isidor',
        'Lenny Joseph','Derrick Etienne jr.',
    ],
    'Schotland' => [
        'Craig Gordon','Angus Gunn','Liam Kelly','Grant Hanley','Jack Hendry','Aaron Hickey','Dom Hyam',
        'Scott McKenna','Nathan Patterson','Anthony Ralston','Andy Robertson','John Souttar','Kieran Tierney',
        'Ryan Christie','Finlay Curtis','Lewis Ferguson','Ben Gannon-Doak','Billy Gilmour','John McGinn',
        'Kenny McLean','Scott McTominay','Che Adams','Lyndon Dykes','George Hirst','Lawrence Shankland','Ross Stewart',
    ],
    // ----- Group D -----
    'Verenigde Staten' => [
        'Chris Brady','Matt Freese','Matt Turner','Max Arfsten','Sergiño Dest','Alex Freeman','Mark McKenzie',
        'Tim Ream','Chris Richards','Antonee Robinson','Miles Robinson','Joe Scally','Auston Trusty',
        'Tyler Adams','Sebastian Berhalter','Weston McKennie','Cristian Roldan','Malik Tillman',
        'Brenden Aaronson','Folarin Balogun','Ricardo Pepi','Haji Wright','Christian Pulisic',
        'Giovanni Reyna','Timothy Weah','Alejandro Zendejas',
    ],
    // ----- Group E -----
    'Duitsland' => [
        'Manuel Neuer','Oliver Baumann','Alexander Nübel','Nico Schlotterbeck','Antonio Rüdiger','David Raum',
        'Jonathan Tah','Waldemar Anton','Nathaniel Brown','Malick Thiaw','Joshua Kimmich','Jamal Musiala',
        'Pascal Gross','Leon Goretzka','Florian Wirtz','Aleksander Pavlovic','Felix Nmecha','Angelo Stiller',
        'Nadiem Amiri','Leroy Sané','Nick Woltemade','Deniz Undav','Jamie Leweling','Kai Havertz',
        'Lennart Karl','Maximilian Beier',
    ],
    'Curaçao' => [
        'Tyrick Bodak','Trevor Doornbusch','Eloy Room','Riechedly Bazoer','Joshua Brenet','Roshon Van Eijma',
        'Sherel Floranus','Deveron Fonville','Juriën Gaari','Armando Obispo','Shurandy Sambo','Juninho Bacuna',
        'Leandro Bacuna','Livano Comenencia','Kevin Felida','Ar\'Jany Martha','Tyrese Noslin',
        'Godfried Roemeratoe','Jeremy Antonisse','Tahith Chong','Kenji Gorré','Sontje Hansen',
        'Gervane Kastaneer','Brandley Kuwas','Jürgen Locadia','Jearl Margaritha',
    ],
    'Ivoorkust' => [
        'Yahia Fofana','Mohamed Koné','Alban Lafont','Ousmane Diomandé','Ghislain Konan','Odilon Kossounou',
        'Guéla Doué','Emmanuel Agbadou','Evan Ndicka','Clement Akpa','Wilfried Singo','Jean Michaël Seri',
        'Seko Fofana','Franck Kessié','Ibrahim Sangaré','Christ Inao Oulaï','Parfait Guiagon',
        'Oumar Diakité','Amad Diallo','Nicolas Pépé','Evann Guessand','Simon Adingra','Ange-Yoan Bonny',
        'Yan Diomande','Bazoumana Toure','Elye Wahi',
    ],
    // ----- Group F -----
    'Nederland' => [
        'Bart Verbruggen','Robin Roefs','Mark Flekken','Denzel Dumfries','Jurrien Timber','Virgil van Dijk',
        'Micky van de Ven','Nathan Aké','Jorrel Hato','Jan Paul van Hecke','Mats Wieffer','Ryan Gravenberch',
        'Frenkie de Jong','Tijjani Reijnders','Teun Koopmeiners','Marten de Roon','Guus Til',
        'Quinten Timber','Donyell Malen','Memphis Depay','Cody Gakpo','Wout Weghorst','Justin Kluivert',
        'Brian Brobbey','Crysencio Summerville','Noa Lang',
    ],
    'Japan' => [
        'Zion Suzuki','Keisuke Osako','Tomoki Hayakawa','Yuta Nagatomo','Shogo Taniguchi','Ko Itakura',
        'Tsuyoshi Watanabe','Takehiro Tomiyasu','Hiroki Ito','Ayumu Seko','Yukinari Sugawara',
        'Junnosuke Suzuki','Wataru Endo','Junya Ito','Daichi Kamada','Ritsu Doan','Ao Tanaka',
        'Kaishu Sano','Yuito Suzuki','Kōki Ogawa','Daizen Maeda','Ayase Ueda','Kento Shiogai',
        'Keisuke Gotō','Keito Nakamura','Takefusa Kubo',
    ],
    'Zweden' => [
        'Kristoffer Nordfeldt','Viktor Johansson','Jacob Widell Zetterström','Daniel Svensson',
        'Victor Lindelöf','Isak Hien','Carl Starfelt','Elliot Stroud','Gustaf Lagerbielke',
        'Gabriel Gudmundsson','Emil Holm','Hjalmar Ekdal','Eric Smith','Yasin Ayari','Lucas Bergvall',
        'Jesper Karlström','Mattias Svanberg','Besfort Zeneli','Taha Ali','Anthony Elanga','Viktor Gyökeres',
        'Gustaf Nilsson','Benjamin Nygren','Alexander Isak','Alexander Bernhardsson','Ken Sema',
    ],
    'Tunesië' => [
        'Aymen Dahmen','Sabri Ben Hassen','Abdelmouhib Chamakh','Yan Valéry','Moutaz Neffati','Dylan Bronn',
        'Raed Chikhaoui','Montassar Talbi','Adem Arous','Omar Rekik','Ali Abdi','Mohamed Ben Hmida',
        'Ellyes Skhiri','Anis Ben Slimane','Rani Khedira','Mortada Ben Ouanes','Ismaël Gharbi',
        'Mohamed Hadj-Mahmoud','Hannibal Mejrbi','Elias Saad','Khalil Ayari','Elias Achouri',
        'Sebastien Tounekti','Hazem Mastouri','Firas Chawat','Rayan Elloumi',
    ],
    // ----- Group G -----
    'België' => [
        'Thibaut Courtois','Senne Lammens','Mike Penders','Arthur Theate','Brandon Mechele','Nathan Ngoy',
        'Koni De Winter','Zeno Debast','Maxim De Cuyper','Joaquin Seys','Thomas Meunier','Timothy Castagne',
        'Nicolas Raskin','Axel Witsel','Hans Vanaken','Kevin De Bruyne','Youri Tielemans','Amadou Onana',
        'Jeremy Doku','Alexis Saelemaekers','Matías Fernández Pardo','Diego Moreira','Romelu Lukaku',
        'Leandro Trossard','Charles De Ketelaere','Dodi Lukebakio',
    ],
    'Egypte' => [
        'Mohamed Elshenawy','Mostafa Shobeir','El-Mahdy Soliman','Mohamed Alaa','Mohamed Hany','Ramy Rabia',
        'Yasser Ibrahim','Tarek Alaa','Mohamed Abdelmonem','Karim Hafez','Hossam Abdelmeguid','Ahmed Fatouh',
        'Marwan Attia','Hamdy Fathy','Mohanad Lasheen','Mahmoud Saber','Emam Ashour','Ahmed Sayed Zizo',
        'Ibrahim Adel','Mostafa Ziko','Nabil Emad Donga','Omar Marmoush','Mahmoud Trézéguet','Mohamed Salah',
        'Hamza Abdelkarim','Aqtay Abdallah','Haissem Hassen',
    ],
    'Nieuw-Zeeland' => [
        'Max Crocombe','Alex Paulsen','Michael Woud','Tim Payne','Francis De Vries','Tyler Bindon',
        'Michael Boxall','Liberato Cacace','Nando Pijnaker','Finn Surman','Callan Elliot','Tommy Smith',
        'Joe Bell','Matt Garbett','Marko Stamenic','Sarpreet Singh','Alex Rufer','Ryan Thomas','Chris Wood',
        'Eli Just','Kosta Barbarouses','Ben Waine','Ben Old','Callum McCowatt','Jesse Randall','Lachlan Bayliss',
    ],
    // ----- Group H -----
    'Spanje' => [
        'Unai Simón','David Raya','Joan Garcia','Marc Cucurella','Alejandro Grimaldo','Pau Cubarsí',
        'Aymeric Laporte','Marc Pubill','Eric Garcia','Marcos Llorente','Pedro Porro','Pedri','Fabián Ruiz',
        'Martín Zubimendi','Gavi','Rodri','Álex Baena','Mikel Merino','Mikel Oyarzabal','Dani Olmo',
        'Nico Williams','Yeremy Pino','Ferran Torres','Borja Iglesias','Victor Munoz','Lamine Yamal',
    ],
    'Kaapverdië' => [
        'Carlos dos Santos','Marcio Rosa','Vozinha','Sidny Cabral','Diney Borges','Logan Costa',
        'Roberto Lopes','Steven Moreira','Wagner Pina','Kelvin Pires','Stopira','Telmo Arcanjo',
        'Deroy Duarte','Laros Duarte','Joao Paulo Fernandes','Jamiro Monteiro','Kevin Pina','Yannick Semedo',
        'Gilson Benchimol','Jovane Cabral','Dailon Livramento','Ryan Mendes','Nuno da Costa','Garry Rodrigues',
        'Willy Semedo','Helio Varela',
    ],
    // ----- Group I -----
    'Frankrijk' => [
        'Mike Maignan','Robin Risser Birckel','Brice Samba','Lucas Digne','Malo Gusto','Lucas Hernandez',
        'Théo Hernandez','Ibrahima Konaté','Jules Koundé','Maxence Lacroix','William Saliba','Dayot Upamecano',
        'N\'Golo Kanté','Manu Koné','Adrien Rabiot','Aurélien Tchouaméni','Warren Zaïre-Emery',
        'Maghnes Akliouche','Bradley Barcola','Rayan Cherki','Ousmane Dembélé','Désiré Doué',
        'Jean-Philippe Mateta','Kylian Mbappé','Michael Olise','Marcus Thuram',
    ],
    'Senegal' => [
        'Édouard Mendy','Mory Diaw','Yehvann Diouf','Krépin Diatta','Antoine Mendy','Kalidou Koulibaly',
        'El Hadji Malick Diouf','Mamadou Sarr','Moussa Niakhaté','Moustapha Mbow','Abdoulaye Seck',
        'Ismail Jakobs','Ilay Camara','Idrissa Gana Gueye','Pape Gueye','Lamine Camara','Habib Diarra',
        'Pathé Ciss','Pape Matar Sarr','Bara Sapoko Ndiaye','Sadio Mané','Ismaïla Sarr','Iliman Ndiaye',
        'Assane Diao','Ibrahim Mbaye','Nicolas Jackson','Bamba Dieng','Cherif Ndiaye',
    ],
    'Noorwegen' => [
        'Orjan Haskjold Nyland','Egil Selvik','Sander Tangvik','Julian Ryerson','Marcus Holmgren Pedersen',
        'David Moller Wolfe','Fredrik Bjorkan','Kristoffer Ajer','Torbjorn Heggem','Leo Skiri Ostigard',
        'Sondre Langas','Henrik Falchener','Martin Odegaard','Sander Berge','Fredrik Aursnes','Patrick Berg',
        'Kristian Thorstvedt','Morten Thorsby','Thelo Aasgaard','Antonio Nusa','Oscar Bobb',
        'Andreas Schjelderup','Jens Petter Hauge','Erling Haaland','Alexander Sørloth','Jørgen Strand Larsen',
    ],
    // ----- Group J -----
    'Oostenrijk' => [
        'Patrick Pentz','Alexander Schlager','Florian Wiegele','David Affengruber','David Alaba','Kevin Danso',
        'Marco Friedl','Philipp Lienhart','Phillipp Mwene','Stefan Posch','Alexander Prass','Michael Svoboda',
        'Christoph Baumgartner','Carney Chukwuemeka','Florian Grillitsch','Konrad Laimer','Marcel Sabitzer',
        'Xaver Schlager','Nicolas Seiwald','Romano Schmid','Alessandro Schöpf','Paul Wanner','Patrick Wimmer',
        'Marko Arnautovic','Michael Gregoritsch','Sasa Kalajdzic',
    ],
    // ----- Group K -----
    'Portugal' => [
        'Diogo Costa','José Sá','Rui Silva','Ricardo Velho','Diogo Dalot','Matheus Nunes','Nélson Semedo',
        'João Cancelo','Nuno Mendes','Gonçalo Inácio','Renato Veiga','Rúben Dias','Tomás Araújo','Rúben Neves',
        'Samuel Costa','João Neves','Vitinha','Bruno Fernandes','Bernardo Silva','João Félix',
        'Francisco Trincão','Francisco Conceição','Pedro Neto','Rafael Leão','Gonçalo Guedes',
        'Gonçalo Ramos','Cristiano Ronaldo',
    ],
    'DR Congo' => [
        'Timothy Fayulu','Lionel Mpasi','Mattieu Epolo','Aaron Wan-Bissaka','Gedeon Kalulu','Chancel Mbemba',
        'Steve Kapuadi','Axel Tuanzebe','Dylan Batubinsika','Aaron Tshibola','Arthur Masuaku','Joris Kayembe',
        'Samuel Moutoussamy','Ngal\'Ayel Mukau','Gaël Kakuta','Charles Pickel','Noah Sadiki','Edo Kayembe',
        'Théo Bongonda','Nathanaël Mbuku','Cédric Bakambu','Simon Banza','Fiston Mayele','Brian Cipenga',
        'Yoane Wissa','Meschack Elia',
    ],
    'Colombia' => [
        'Camilo Vargas','Álvaro Montero','David Ospina','Dávinson Sánchez','Jhon Lucumí','Yerry Mina',
        'Willer Ditta','Daniel Muñoz','Santiago Arias','Johan Mojica','Deiver Machado','Richard Ríos',
        'Jefferson Lerma','Kevin Castaño','Juan Camilo Portilla','Gustavo Puerta','Jhon Arias',
        'Jorge Carrascal','Juan Fernando Quintero','James Rodríguez','Jaminton Campaz','Juan Camilo Hernández',
        'Luis Díaz','Luis Suárez','Carlos Andrés Gómez','Jhon Córdoba',
    ],
    // ----- Group L -----
    'Engeland' => [
        'Jordan Pickford','Dean Henderson','James Trafford','Ezri Konsa','Jarell Quansah','Tino Livramento',
        'Dan Burn','Marc Guéhi','John Stones','Djed Spence','Nico O\'Reilly','Reece James','Kobbie Mainoo',
        'Elliot Anderson','Declan Rice','Jude Bellingham','Jordan Henderson','Eberechi Eze','Morgan Rogers',
        'Bukayo Saka','Noni Madueke','Anthony Gordon','Marcus Rashford','Harry Kane','Ivan Toney','Ollie Watkins',
    ],
    'Kroatië' => [
        'Dominik Livaković','Dominik Kotarski','Ivor Pandur','Joško Gvardiol','Duje Ćaleta-Car','Josip Šutalo',
        'Josip Stanišić','Marin Pongračić','Martin Erlić','Luka Vušković','Luka Modrić','Mateo Kovačić',
        'Mario Pašalić','Nikola Vlašić','Luka Sučić','Martin Baturina','Kristijan Jakić','Petar Sučić',
        'Nikola Moro','Toni Fruk','Ivan Perišić','Andrej Kramarić','Ante Budimir','Marco Pašalić',
        'Petar Musa','Igor Matanović',
    ],
    'Panama' => [
        'Orlando Mosquera','Luis Mejía','César Samudio','César Blackman','Jorge Gutiérrez','Amir Murillo',
        'Fidel Escobar','Andrés Andrade','Edgardo Fariña','José Córdoba','Eric Davis','Jiovani Ramos',
        'Roderick Miller','Aníbal Godoy','Adalberto Carrasquilla','Carlos Harvey','Cristian Martínez',
        'José Luis Rodríguez','Cesar Yanis','Yoel Bárcenas','Alberto Quintero','Azarías Londoño',
        'Ismael Díaz','Cecilio Waterman','José Fajardo','Tomás Rodríguez',
    ],
];

$inserted = $skipped = 0;
$missingTeams = [];

foreach ($squads as $teamName => $players) {
    $teamId = (int) Database::fetchColumn('SELECT id FROM teams WHERE name = ?', [$teamName]);
    if (!$teamId) {
        $missingTeams[] = $teamName;
        continue;
    }
    foreach ($players as $name) {
        $exists = (int) Database::fetchColumn(
            'SELECT id FROM players WHERE LOWER(name) = LOWER(?) AND team_id = ?',
            [$name, $teamId]
        );
        if ($exists) { $skipped++; continue; }
        Database::insert('players', ['name' => $name, 'team_id' => $teamId]);
        $inserted++;
    }
}

echo "✓ {$inserted} spelers toegevoegd, {$skipped} bestonden al.\n";
if ($missingTeams) {
    echo "⚠ Teams niet gevonden (controleer namen in /admin/teams):\n  - " . implode("\n  - ", $missingTeams) . "\n";
}
echo "ℹ Selecties van Tsjechië, Mexico, Canada, Qatar, Paraguay, Australië, Turkije, Ecuador, Iran,\n";
echo "  Saoedi-Arabië, Uruguay, Irak, Argentinië, Algerije, Jordanië, Oezbekistan, Ghana\n";
echo "  zijn nog niet bekend bij ESPN — vul ze later aan via /admin/players.\n";
