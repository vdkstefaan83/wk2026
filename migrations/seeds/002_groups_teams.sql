-- Groups A-L (placeholder draw - admin can adjust via UI)
INSERT INTO team_groups (code, name, sort_order) VALUES
('A','Groep A',1),('B','Groep B',2),('C','Groep C',3),('D','Groep D',4),
('E','Groep E',5),('F','Groep F',6),('G','Groep G',7),('H','Groep H',8),
('I','Groep I',9),('J','Groep J',10),('K','Groep K',11),('L','Groep L',12);

-- 48 teams (placeholder draw; verify via admin)
INSERT INTO teams (name, iso3, flag_emoji, group_id) VALUES
-- Group A
('Mexico','MEX','🇲🇽',(SELECT id FROM team_groups WHERE code='A')),
('Kroatië','CRO','🇭🇷',(SELECT id FROM team_groups WHERE code='A')),
('Kameroen','CMR','🇨🇲',(SELECT id FROM team_groups WHERE code='A')),
('Saoedi-Arabië','KSA','🇸🇦',(SELECT id FROM team_groups WHERE code='A')),
-- Group B
('Canada','CAN','🇨🇦',(SELECT id FROM team_groups WHERE code='B')),
('België','BEL','🇧🇪',(SELECT id FROM team_groups WHERE code='B')),
('Tunesië','TUN','🇹🇳',(SELECT id FROM team_groups WHERE code='B')),
('Jordanië','JOR','🇯🇴',(SELECT id FROM team_groups WHERE code='B')),
-- Group C
('Verenigde Staten','USA','🇺🇸',(SELECT id FROM team_groups WHERE code='C')),
('Zwitserland','SUI','🇨🇭',(SELECT id FROM team_groups WHERE code='C')),
('Nigeria','NGA','🇳🇬',(SELECT id FROM team_groups WHERE code='C')),
('Irak','IRQ','🇮🇶',(SELECT id FROM team_groups WHERE code='C')),
-- Group D
('Argentinië','ARG','🇦🇷',(SELECT id FROM team_groups WHERE code='D')),
('Denemarken','DEN','🇩🇰',(SELECT id FROM team_groups WHERE code='D')),
('Senegal','SEN','🇸🇳',(SELECT id FROM team_groups WHERE code='D')),
('Nieuw-Zeeland','NZL','🇳🇿',(SELECT id FROM team_groups WHERE code='D')),
-- Group E
('Frankrijk','FRA','🇫🇷',(SELECT id FROM team_groups WHERE code='E')),
('Oostenrijk','AUT','🇦🇹',(SELECT id FROM team_groups WHERE code='E')),
('Algerije','ALG','🇩🇿',(SELECT id FROM team_groups WHERE code='E')),
('Costa Rica','CRC','🇨🇷',(SELECT id FROM team_groups WHERE code='E')),
-- Group F
('Brazilië','BRA','🇧🇷',(SELECT id FROM team_groups WHERE code='F')),
('Servië','SRB','🇷🇸',(SELECT id FROM team_groups WHERE code='F')),
('Ghana','GHA','🇬🇭',(SELECT id FROM team_groups WHERE code='F')),
('Panama','PAN','🇵🇦',(SELECT id FROM team_groups WHERE code='F')),
-- Group G
('Engeland','ENG','🏴󠁧󠁢󠁥󠁮󠁧󠁿',(SELECT id FROM team_groups WHERE code='G')),
('Nederland','NED','🇳🇱',(SELECT id FROM team_groups WHERE code='G')),
('Marokko','MAR','🇲🇦',(SELECT id FROM team_groups WHERE code='G')),
('Zuid-Korea','KOR','🇰🇷',(SELECT id FROM team_groups WHERE code='G')),
-- Group H
('Spanje','ESP','🇪🇸',(SELECT id FROM team_groups WHERE code='H')),
('Tsjechië','CZE','🇨🇿',(SELECT id FROM team_groups WHERE code='H')),
('Ivoorkust','CIV','🇨🇮',(SELECT id FROM team_groups WHERE code='H')),
('Australië','AUS','🇦🇺',(SELECT id FROM team_groups WHERE code='H')),
-- Group I
('Duitsland','GER','🇩🇪',(SELECT id FROM team_groups WHERE code='I')),
('Portugal','POR','🇵🇹',(SELECT id FROM team_groups WHERE code='I')),
('Egypte','EGY','🇪🇬',(SELECT id FROM team_groups WHERE code='I')),
('Japan','JPN','🇯🇵',(SELECT id FROM team_groups WHERE code='I')),
-- Group J
('Italië','ITA','🇮🇹',(SELECT id FROM team_groups WHERE code='J')),
('Polen','POL','🇵🇱',(SELECT id FROM team_groups WHERE code='J')),
('Iran','IRN','🇮🇷',(SELECT id FROM team_groups WHERE code='J')),
('Bolivia','BOL','🇧🇴',(SELECT id FROM team_groups WHERE code='J')),
-- Group K
('Uruguay','URU','🇺🇾',(SELECT id FROM team_groups WHERE code='K')),
('Noorwegen','NOR','🇳🇴',(SELECT id FROM team_groups WHERE code='K')),
('DR Congo','COD','🇨🇩',(SELECT id FROM team_groups WHERE code='K')),
('Oezbekistan','UZB','🇺🇿',(SELECT id FROM team_groups WHERE code='K')),
-- Group L
('Colombia','COL','🇨🇴',(SELECT id FROM team_groups WHERE code='L')),
('Paraguay','PAR','🇵🇾',(SELECT id FROM team_groups WHERE code='L')),
('Ecuador','ECU','🇪🇨',(SELECT id FROM team_groups WHERE code='L')),
('Jamaica','JAM','🇯🇲',(SELECT id FROM team_groups WHERE code='L'))
;
