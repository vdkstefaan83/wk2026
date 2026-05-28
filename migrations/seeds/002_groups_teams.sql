-- WK2026 Final Draw (source: Sporza)
INSERT INTO team_groups (code, name, sort_order) VALUES
('A','Group A',1),('B','Group B',2),('C','Group C',3),('D','Group D',4),
('E','Group E',5),('F','Group F',6),('G','Group G',7),('H','Group H',8),
('I','Group I',9),('J','Group J',10),('K','Group K',11),('L','Group L',12);

INSERT INTO teams (name, iso3, flag_emoji, group_id) VALUES
-- Group A
('Mexico','MEX','🇲🇽',(SELECT id FROM team_groups WHERE code='A')),
('South Africa','RSA','🇿🇦',(SELECT id FROM team_groups WHERE code='A')),
('South Korea','KOR','🇰🇷',(SELECT id FROM team_groups WHERE code='A')),
('Czech Republic','CZE','🇨🇿',(SELECT id FROM team_groups WHERE code='A')),
-- Group B
('Canada','CAN','🇨🇦',(SELECT id FROM team_groups WHERE code='B')),
('Bosnia and Herzegovina','BIH','🇧🇦',(SELECT id FROM team_groups WHERE code='B')),
('Qatar','QAT','🇶🇦',(SELECT id FROM team_groups WHERE code='B')),
('Switzerland','SUI','🇨🇭',(SELECT id FROM team_groups WHERE code='B')),
-- Group C
('Brazil','BRA','🇧🇷',(SELECT id FROM team_groups WHERE code='C')),
('Morocco','MAR','🇲🇦',(SELECT id FROM team_groups WHERE code='C')),
('Haiti','HAI','🇭🇹',(SELECT id FROM team_groups WHERE code='C')),
('Scotland','SCO','🏴󠁧󠁢󠁳󠁣󠁴󠁿',(SELECT id FROM team_groups WHERE code='C')),
-- Group D
('United States','USA','🇺🇸',(SELECT id FROM team_groups WHERE code='D')),
('Paraguay','PAR','🇵🇾',(SELECT id FROM team_groups WHERE code='D')),
('Australia','AUS','🇦🇺',(SELECT id FROM team_groups WHERE code='D')),
('Turkey','TUR','🇹🇷',(SELECT id FROM team_groups WHERE code='D')),
-- Group E
('Germany','GER','🇩🇪',(SELECT id FROM team_groups WHERE code='E')),
('Curaçao','CUW','🇨🇼',(SELECT id FROM team_groups WHERE code='E')),
('Ivory Coast','CIV','🇨🇮',(SELECT id FROM team_groups WHERE code='E')),
('Ecuador','ECU','🇪🇨',(SELECT id FROM team_groups WHERE code='E')),
-- Group F
('Netherlands','NED','🇳🇱',(SELECT id FROM team_groups WHERE code='F')),
('Japan','JPN','🇯🇵',(SELECT id FROM team_groups WHERE code='F')),
('Sweden','SWE','🇸🇪',(SELECT id FROM team_groups WHERE code='F')),
('Tunisia','TUN','🇹🇳',(SELECT id FROM team_groups WHERE code='F')),
-- Group G
('Belgium','BEL','🇧🇪',(SELECT id FROM team_groups WHERE code='G')),
('Egypt','EGY','🇪🇬',(SELECT id FROM team_groups WHERE code='G')),
('Iran','IRN','🇮🇷',(SELECT id FROM team_groups WHERE code='G')),
('New Zealand','NZL','🇳🇿',(SELECT id FROM team_groups WHERE code='G')),
-- Group H
('Spain','ESP','🇪🇸',(SELECT id FROM team_groups WHERE code='H')),
('Cape Verde','CPV','🇨🇻',(SELECT id FROM team_groups WHERE code='H')),
('Saudi Arabia','KSA','🇸🇦',(SELECT id FROM team_groups WHERE code='H')),
('Uruguay','URU','🇺🇾',(SELECT id FROM team_groups WHERE code='H')),
-- Group I
('France','FRA','🇫🇷',(SELECT id FROM team_groups WHERE code='I')),
('Senegal','SEN','🇸🇳',(SELECT id FROM team_groups WHERE code='I')),
('Iraq','IRQ','🇮🇶',(SELECT id FROM team_groups WHERE code='I')),
('Norway','NOR','🇳🇴',(SELECT id FROM team_groups WHERE code='I')),
-- Group J
('Argentina','ARG','🇦🇷',(SELECT id FROM team_groups WHERE code='J')),
('Algeria','ALG','🇩🇿',(SELECT id FROM team_groups WHERE code='J')),
('Austria','AUT','🇦🇹',(SELECT id FROM team_groups WHERE code='J')),
('Jordan','JOR','🇯🇴',(SELECT id FROM team_groups WHERE code='J')),
-- Group K
('Portugal','POR','🇵🇹',(SELECT id FROM team_groups WHERE code='K')),
('DR Congo','COD','🇨🇩',(SELECT id FROM team_groups WHERE code='K')),
('Uzbekistan','UZB','🇺🇿',(SELECT id FROM team_groups WHERE code='K')),
('Colombia','COL','🇨🇴',(SELECT id FROM team_groups WHERE code='K')),
-- Group L
('England','ENG','🏴󠁧󠁢󠁥󠁮󠁧󠁿',(SELECT id FROM team_groups WHERE code='L')),
('Croatia','CRO','🇭🇷',(SELECT id FROM team_groups WHERE code='L')),
('Ghana','GHA','🇬🇭',(SELECT id FROM team_groups WHERE code='L')),
('Panama','PAN','🇵🇦',(SELECT id FROM team_groups WHERE code='L'))
;
