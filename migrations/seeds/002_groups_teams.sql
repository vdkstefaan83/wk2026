-- WK2026 Final Draw (bron: Sporza)
INSERT INTO team_groups (code, name, sort_order) VALUES
('A','Groep A',1),('B','Groep B',2),('C','Groep C',3),('D','Groep D',4),
('E','Groep E',5),('F','Groep F',6),('G','Groep G',7),('H','Groep H',8),
('I','Groep I',9),('J','Groep J',10),('K','Groep K',11),('L','Groep L',12);

INSERT INTO teams (name, iso3, flag_emoji, group_id) VALUES
-- Group A
('Mexico','MEX','🇲🇽',(SELECT id FROM team_groups WHERE code='A')),
('Zuid-Afrika','RSA','🇿🇦',(SELECT id FROM team_groups WHERE code='A')),
('Zuid-Korea','KOR','🇰🇷',(SELECT id FROM team_groups WHERE code='A')),
('Tsjechië','CZE','🇨🇿',(SELECT id FROM team_groups WHERE code='A')),
-- Group B
('Canada','CAN','🇨🇦',(SELECT id FROM team_groups WHERE code='B')),
('Bosnië en Herzegovina','BIH','🇧🇦',(SELECT id FROM team_groups WHERE code='B')),
('Qatar','QAT','🇶🇦',(SELECT id FROM team_groups WHERE code='B')),
('Zwitserland','SUI','🇨🇭',(SELECT id FROM team_groups WHERE code='B')),
-- Group C
('Brazilië','BRA','🇧🇷',(SELECT id FROM team_groups WHERE code='C')),
('Marokko','MAR','🇲🇦',(SELECT id FROM team_groups WHERE code='C')),
('Haïti','HAI','🇭🇹',(SELECT id FROM team_groups WHERE code='C')),
('Schotland','SCO','🏴󠁧󠁢󠁳󠁣󠁴󠁿',(SELECT id FROM team_groups WHERE code='C')),
-- Group D
('Verenigde Staten','USA','🇺🇸',(SELECT id FROM team_groups WHERE code='D')),
('Paraguay','PAR','🇵🇾',(SELECT id FROM team_groups WHERE code='D')),
('Australië','AUS','🇦🇺',(SELECT id FROM team_groups WHERE code='D')),
('Turkije','TUR','🇹🇷',(SELECT id FROM team_groups WHERE code='D')),
-- Group E
('Duitsland','GER','🇩🇪',(SELECT id FROM team_groups WHERE code='E')),
('Curaçao','CUW','🇨🇼',(SELECT id FROM team_groups WHERE code='E')),
('Ivoorkust','CIV','🇨🇮',(SELECT id FROM team_groups WHERE code='E')),
('Ecuador','ECU','🇪🇨',(SELECT id FROM team_groups WHERE code='E')),
-- Group F
('Nederland','NED','🇳🇱',(SELECT id FROM team_groups WHERE code='F')),
('Japan','JPN','🇯🇵',(SELECT id FROM team_groups WHERE code='F')),
('Zweden','SWE','🇸🇪',(SELECT id FROM team_groups WHERE code='F')),
('Tunesië','TUN','🇹🇳',(SELECT id FROM team_groups WHERE code='F')),
-- Group G
('België','BEL','🇧🇪',(SELECT id FROM team_groups WHERE code='G')),
('Egypte','EGY','🇪🇬',(SELECT id FROM team_groups WHERE code='G')),
('Iran','IRN','🇮🇷',(SELECT id FROM team_groups WHERE code='G')),
('Nieuw-Zeeland','NZL','🇳🇿',(SELECT id FROM team_groups WHERE code='G')),
-- Group H
('Spanje','ESP','🇪🇸',(SELECT id FROM team_groups WHERE code='H')),
('Kaapverdië','CPV','🇨🇻',(SELECT id FROM team_groups WHERE code='H')),
('Saudi-Arabië','KSA','🇸🇦',(SELECT id FROM team_groups WHERE code='H')),
('Uruguay','URU','🇺🇾',(SELECT id FROM team_groups WHERE code='H')),
-- Group I
('Frankrijk','FRA','🇫🇷',(SELECT id FROM team_groups WHERE code='I')),
('Senegal','SEN','🇸🇳',(SELECT id FROM team_groups WHERE code='I')),
('Irak','IRQ','🇮🇶',(SELECT id FROM team_groups WHERE code='I')),
('Noorwegen','NOR','🇳🇴',(SELECT id FROM team_groups WHERE code='I')),
-- Group J
('Argentinië','ARG','🇦🇷',(SELECT id FROM team_groups WHERE code='J')),
('Algerije','ALG','🇩🇿',(SELECT id FROM team_groups WHERE code='J')),
('Oostenrijk','AUT','🇦🇹',(SELECT id FROM team_groups WHERE code='J')),
('Jordanië','JOR','🇯🇴',(SELECT id FROM team_groups WHERE code='J')),
-- Group K
('Portugal','POR','🇵🇹',(SELECT id FROM team_groups WHERE code='K')),
('DR Congo','COD','🇨🇩',(SELECT id FROM team_groups WHERE code='K')),
('Oezbekistan','UZB','🇺🇿',(SELECT id FROM team_groups WHERE code='K')),
('Colombia','COL','🇨🇴',(SELECT id FROM team_groups WHERE code='K')),
-- Group L
('Engeland','ENG','🏴󠁧󠁢󠁥󠁮󠁧󠁿',(SELECT id FROM team_groups WHERE code='L')),
('Kroatië','CRO','🇭🇷',(SELECT id FROM team_groups WHERE code='L')),
('Ghana','GHA','🇬🇭',(SELECT id FROM team_groups WHERE code='L')),
('Panama','PAN','🇵🇦',(SELECT id FROM team_groups WHERE code='L'))
;
