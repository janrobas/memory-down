# Standard za workflowe v MemoryDownu (v1)

Ta dokument določa, kako so **definicije avtomatizacij (workflowov)** zapisane v
MemoryDownu in pod katerimi pogoji jih sme izvesti prihodnji C#/.NET izvajalec.
Nastavlja obliko in pravila; ne opisuje še neobstoječe implementacije.

- **MemoryDown** je avtoritativni vir definicij workflowov in trajnega spomina.
- **Izvajalec** (C# Worker Service, še ne obstaja) bere definicije, odloča, kdaj
  se izvedejo, in izvaja korake prek skupnega Tool Registryja.
- **SQLite v izvajalcu** hrani samo operativno stanje (zadnji uspešni zagoni,
  idempotenca, predlogi, revizijska sled). **Nikoli** ni vir definicij.
- **Infomaniak** ostaja avtoritativni vir koledarja in e-pošte.

---

## 1. Kaj je workflow in kaj ni

**Workflow je zapis v kategoriji `workflows`, ki nosi oznako `executable`.**

| Zapis | Kaj je |
| --- | --- |
| `workflows/…` **brez** oznake `executable` | navaden zapisek, osnutek ali opis postopka v naravnem jeziku. **Nikoli se ne izvede.** |
| `workflows/…` **z** oznako `executable` | definicija, ki jo izvajalec sme izvesti (če so izpolnjeni pogoji iz razdelka 8) |

Zapisi, kot sta »IdPortal način dela pri spremembah« in »OpenCode + Ollama
Qwen3«, so zapiski o postopkih — koristni, vendar **niso** definicije za
izvajanje in se ne izvedejo. Enako velja za vsak osnutek, primer ali nepopolno
definicijo: **navodila v naravnem jeziku niso dovoljenje za izvajanje.**

---

## 2. Kje živi kaj

| Podatek | Kje | Kdo ga piše |
| --- | --- | --- |
| klasifikacija zapisa (oznaka `executable`) | frontmatter v Markdownu | **samo človek v admin UI** |
| pavza / arhiviranost | frontmatter (`archived`) | **samo človek v admin UI** (za zapis z oznako) |
| operativna konfiguracija | **telo** dokumenta (fenced `yaml` blok) | samo človek v admin UI (zapis z oznako je prek MCP samo za branje) |
| navodila | **telo** dokumenta, v naravnem jeziku | isto |
| kdaj se je izvedlo, kaj je bilo predlagano/odobreno | SQLite izvajalca | izvajalec |

Definicije se berejo na tri načine — vsi trije berejo iste Markdown datoteke, noben
ni drugi vir resnice:

1. neposredno z diska (če izvajalec teče ob datotekah),
2. prek MCP (`list_memory`, `read_memory`),
3. prek **izklopljenega po privzetku, žetonsko zaščitenega API-ja**
   `GET /api/workflows[/{id}]` (glej `README.md`), če izvajalec nima dostopa do
   datotek.

Branje definicije **ne avtorizira ničesar**: izvajalec mora pogoje iz razdelka 8
preveriti sam, ne glede na to, od kod je definicijo dobil. API ne vrača verdikta,
ker verdikt ni njegova odločitev.

**Zakaj je konfiguracija v telesu in ne v frontmatterju?** Ker MemoryDown ob
vsakem pisanju sestavi frontmatter na novo iz fiksnega seznama ključev
(`type`, `tags`, `created`, `updated`, `source`, `id`, `archived`, `public`) in
neznanih ključev ne ohrani — izmerjeno: eno shranjevanje v admin UI izbriše
`enabled:` iz frontmatterja. Ker admin UI samodejno shranjuje, bi bila takšna
konfiguracija izgubljena takoj. Telo se pri shranjevanju prepiše le v celoti,
zato fenced blok preživi vsako urejanje. Druga posledica iste lastnosti je
ključna za varnost: **oznaka preživi prepis telesa**, zato oznaka sama ne more
biti ključavnica (glej razdelek 5).

---

## 3. Kanonična struktura zapisa

Ko v admin UI ustvariš nov zapis v kategoriji `workflows`, se telo **prednapolni
s predlogo** (glej razdelek 11). To je torej kanonična oblika enega izvedljivega
workflowa:

````markdown
---
type: workflows
tags:
  - executable
created: 2026-10-15T09:00:00Z
updated: 2026-10-15T09:00:00Z
source: chatgpt
id: jutranji-pregled-poste-1a2b3c
---

# Jutranji pregled pošte

```yaml
# Kdaj naj se zažene: 5-poljski cron (minuta ura dan-v-mesecu dan-v-tednu), v
# časovnem pasu spodaj. Brez te vrstice se zažene samo ročno.
# schedule: "0 7 * * *"
timezone: Europe/Ljubljana

# Kako se obnaša: dry-run samo bere in poroča, kaj bi naredil. apply sme
# spreminjati, vsako mutirajoče dejanje pa potrdiš ti ob klicu. yolo izvede vsa
# orodja z allowed_tools takoj, brez predloga in brez potrditve — velja le, če
# ima izvajalec vklopljeno svoje stikalo (AIORCH_ENABLE_YOLO=true, razdelek 7).
mode: dry-run

# Katera orodja sme klicati. Kar ni na seznamu, izvajalec zavrne. Brez seznama
# sme le brati. Namesto id-jev lahko vpišeš * (vsa orodja vseh strežnikov) ali
# calendar.* (vsa orodja enega strežnika) — * vedno v narekovajih ("*"), ker je
# sam * v YAML alias. Namesto seznama je dovoljen tudi en sam id.
allowed_tools:
  - memory.search_memory
  - memory.read_memory

# S katerim modelom naj teče. Vpiši pravi id modela.
llm_provider: opencode-go
llm_model: <model-id>
```

## Namen

TODO: kaj naj workflow naredi in zakaj.

## Navodila

1. TODO: koraki, ki naj jih izvajalec izvede.
2. Če je karkoli nejasno ali manjka podatek, pripravi predlog in vprašanje namesto ugibanja.

## Poročilo

Na koncu vedno pošlji kratek povzetek: kaj je bilo narejeno, kaj je negotovo in kaj potrebuje mojo odločitev.
````

Pravila oblike:

- **Naslov (H1) ni del telesa.** Vpiše se v polje *Title* v admin UI, store pa ga
  zapiše kot `# …` med frontmatter in telo; ob branju ga spet odstrani. Zato se
  telo (in prednastavljena predloga) začne s konfiguracijskim blokom. Če naslova
  ne vpišeš, zapis nima H1 in je v seznamu prikazan po svojem id-ju.
- **Konfiguracijski blok je prvi fenced blok v telesu** z oznako jezika `yaml`.
  Natanko eden na dokument. Vrstice, ki se v bloku začnejo z `#`, so YAML
  komentarji — niso naslovi in ne štejejo kot vsebina.
- Za blokom so navodila v naravnem jeziku (slovensko ali angleško), lahko tudi
  z vmesnimi naslovi `##`.
- Predloga je namerno **osnutek**: vsebuje `TODO` in `<model-id>`, zato je
  izvajalec ne izvede, dokler je ne izpolniš (razdelek 8, 6. pogoj).
- Dokument ne vsebuje skrivnosti (gesel, žetonov). Navodila naj jih ne
  zahtevajo: izvajalec jih bere iz svoje konfiguracije.

---

## 4. Polja

| Polje | Tip | Privzeto (če manjka) | Pomen |
| --- | --- | --- | --- |
| `mode` | `dry-run` \| `apply` \| `yolo` | `dry-run` | `dry-run`: samo bralna orodja, vsaka predvidena sprememba se poroča. `apply`: dovoljene so spremembe, vsako mutirajoče orodje zahteva tvojo potrditev ob klicu. `yolo`: vsa orodja z `allowed_tools` se izvedejo takoj, brez predloga in brez potrditve — **velja le, če ima izvajalec vklopljeno svoje stikalo** (razdelek 7); sicer način teče kot `apply`. |
| `schedule` | cron (5 polj) | odsotno = samo ročni zagon | Kdaj se izvede, v času `timezone`. |
| `timezone` | IANA ime | `Europe/Ljubljana` | Časovni pas urnika in poročil. |
| `allowed_tools` | seznam id-jev (dovoljen je tudi žolček) ali en sam id | odsotno = samo bralna orodja | Katera orodja workflow sploh sme klicati. Kar ni na seznamu, izvajalec zavrne. Namesto posameznih id-jev sta dovoljena `*` (vsa orodja vseh strežnikov) in `<strežnik>.*` (npr. `calendar.*`). Namesto seznama je dovoljena tudi skalarna oblika (`allowed_tools: "*"`), ki pomeni enoelementni seznam. |
| `llm_provider` | ime ponudnika | odsotno = privzeti ponudnik izvajalca | S katerim ponudnikom naj teče. Imena razrešuje izvajalec; neznano ime javi kot napako, ne ugiba. |
| `llm_model` | id modela | odsotno = privzeti model ponudnika | Kateri model naj teče. |

Dodatna pravila:

- **Neznana polja** izvajalec ignorira in o tem **opozori v poročilu**. Neznano
  polje nikoli ne razširi dovoljenj.
- **Polja `enabled`, `require_confirmation` in `auto_approve` ne obstajajo.**
  `enabled` nadomeščata oznaka in arhiviranje; potrditev je lastnost orodja in
  odločitev uporabnika, ne polje v definiciji; samodejno odobritev izraža
  `mode: yolo` skupaj s stikalom izvajalca (razdelek 7) — dva človeška dejanja,
  ne polje.
- **Blok je ploski seznam `ključ: vrednost`**, brez gnezdenih preslikav. Seznam
  (`allowed_tools`) je ena vrstica `- id` na element; `#` na začetku vrstice je
  komentar; vrednost v narekovajih obdrži presledke (`schedule: "0 7 * * *"`).
  Če `allowed_tools` vsebuje en sam id (tudi žolček), je dovoljena tudi skalarna
  oblika — `allowed_tools: calendar.get_events` ali `allowed_tools: "*"` — in
  šteje kot enoelementni seznam.
- **`allowed_tools` pozna dva žolčka**: `*` (vsa orodja vseh povezanih strežnikov)
  in `<strežnik>.*` (vsa orodja enega strežnika, npr. `calendar.*`). Razširi ju
  **izvajalec** ob dejanskem Tool Registryju pred zagonom; definicija sama ostane
  besedilo. Žolček je zato edino mesto, kjer definicija sčasoma dobi pravice: kar
  strežnik ponudi kasneje, je z `*` že dovoljeno. Žolček, ki se razširi v **nič**
  (npr. strežnik ni dosegljiv), je napaka in zagon se preskoči — nikoli se ne
  razume kot "nič ni dovoljeno". `*` se v seznamu piše **v narekovajih**
  (`- "*"`): goli `*` je v YAML alias in ga izvajalec z resničnim YAML parserjem
  zavrne (MemoryDown ga prebere, ker blok bere plosko, in tega ne popravlja).
  `<strežnik>.*` (npr. `calendar.*`) narekovajev ne potrebuje.
- **`schedule` sprejme številke** in običajna tričrkovna imena (`*`, `7`,
  `7,12`, `6-9`, `*/4`, `mon-fri`, `jan`).
- Izvajalec naj za razvrščanje orodij med bralna in mutirajoča uporabi oznake
  orodij iz Tool Registryja (MCP orodja nosijo `readOnlyHint`), ne lastnega
  seznama imen.

MemoryDown tega ne uveljavlja; v admin UI (glej razdelek 5) preveri le toliko,
da ti pove, kaj bi izvajalec zavrnil. Polje, ki ga MemoryDown ne pozna, je za
izvajalca neznano polje — zato se nova polja dodajo tukaj, ne ad hoc.

---

## 5. Oznaka `executable` je rezervirana in človeška

- Oznako `executable` **lahko doda samo admin UI**. `remember` in
  `update_memory` prek MCP jo zavrneta.
- Oznaka je dovoljena **samo v kategoriji `workflows`** — v obe smeri:
  - zapisa zunaj `workflows` ni mogoče označiti (admin UI oznake tam niti ne
    ponudi, admin API pa shranjevanje zavrne, namesto da bi oznako tiho odstranil);
  - aktiviranega workflowa ni mogoče premakniti iz `workflows`, dokler oznake ne
    odstraniš.
  Prva omejitev prepreči, da bi oznaka označevala nekaj, kar izvajalec nikoli ne
  bi gledal; druga, da bi definicija ušla iz edinega seznama, ki ga izvajalec bere.
- Vsak zapis, ki oznako nosi, je prek MCP **samo za branje**: `update_memory`
  ne more spremeniti telesa, oznak, kategorije (premika) ali arhiviranja,
  `forget_memory` pa ga ne more izbrisati. Branje (`recall`, `search_memory`,
  `read_memory`, `list_memory`) je vedno dovoljeno.
- Zato lahko agent workflow **predlaga** (zapiše osnutek brez oznake), nikoli pa
  ga ne more **aktivira** ali prepisati.

Admin UI ob zapisu z oznako pokaže **verdikt**: kaj bi izvajalec zavrnil
(razdelek 8). To ni uveljavljanje — MemoryDown shranjuje tudi nepopolne osnutke —
ampak opozorilo, preden se zaneseš na definicijo, ki se ne bo izvedla.

Zakaj tako:

1. **Agent si ne sme podeliti dovoljenja.** Če bi oznako lahko dodal MCP, bi si
   agent (tudi posredno, prek vsebine e-pošte) odobril lastno avtomatizacijo.
2. **Oznaka sama ne zadošča.** Izmerjeno: oznaka preživi prepis telesa. Brez
   pravila »z oznako = samo za branje« bi lahko agent pod obstoječo oznako
   zamenjal navodila in `allowed_tools`, izvedlo pa bi se nekaj, česar uporabnik
   ni odobril. Zato je meja: kar nosi oznako, je agentu nedosegljivo.

Posledica: **aktivacija in urejanje workflowa sta človeška poteza v admin UI.**
Upravljanje workflowov prek Telegrama (poznejša faza) bo zato moralo iti prek
predlogov, ki jih človek potrdi, ne prek neposrednega pisanja prek MCP.

---

## 6. Urniki in čas

- `schedule` je **5-poljski cron** (`minuta ura dan v-mesecu dan-v-tednu`),
  brez sekund. Primeri:
  - `0 7,12 * * *` — vsak dan ob 07:00 in 12:00,
  - `30 6 * * 1-5` — ob delavnikih ob 06:30,
  - `0 */4 * * *` — vsake 4 ure.
- Ure so **lokalne v `timezone`**. Izvajalec mora pravilno upoštevati poletni in
  zimski čas (IANA pravila, ne fiksnega odmika).
- Odsoten ali prazen `schedule` pomeni **samo ročni zagon** (uporabnik ali
  izvajalec ga sproži posebej).
- **Idempotenca:** ključ izvedbe je `category/id` + načrtovani trenutek v UTC.
  Ponoven zagon izvajalca ali ponovno branje definicije ne sme povzročiti druge
  izvedbe istega obdobja. Zamujene izvedbe se nadomestijo **največ enkrat**; če
  je zamuda večja od 3 ur, se zabeleži kot izpuščena, ne izvedena.
- Sprememba definicije med izvajanjem velja od **naslednjega** zagona; trenutni
  zagon se zaključi po pravilih, s katerimi je začel.

---

## 7. Načini delovanja, stikalo izvajalca in potrditve

| | `dry-run` | `apply` | `yolo` |
| --- | --- | --- | --- |
| bralna orodja | izvede | izvede | izvede |
| mutirajoča orodja | **nikoli** — poroča predlog | izvede jih **samo po tvoji potrditvi ob klicu** | **izvede jih takoj, brez predloga in brez potrditve** |
| kaj dobiš | povzetek, predloge, vprašanja | povzetek + potrjena dejanja | povzetek + **opravljena** dejanja |
| učinkovit način, kadar stikalo izvajalca ni vklopljeno | `dry-run` | `apply` (za pisanje `dry-run`, dokler ni kanala) | `apply` |

**`yolo` ni samodejen.** Definicija izrazi *zahtevo*, izvajalec pa jo uresniči
samo, če ima vklopljeno svoje stikalo — v tem izvajalcu `AIORCH_ENABLE_YOLO=true`
v **njegovem** okolju (`.env` izvajalca, ne MemoryDowna). Stikalo je instančno:
privzeto je izklopljeno, brez njega se `mode: yolo` obnaša natanko kot `apply`,
in definicija ga ne more vklopiti. Odobritev je torej **dvakrat človeška**: z
načinom v telesu definicije (ki ga prek MCP ni mogoče zapisati, razdelek 5) in s
stikalom v okolju izvajalca. Stikalo samo ne spremeni ničesar, dokler v definiciji
ne piše `yolo`.

Trije pojmi, ki jih ne gre mešati:

1. **Dovoljeno** — orodje je na seznamu `allowed_tools`. To je zgornja meja in
   velja v vseh treh načinih. Žolček (`*`, `calendar.*`) tu ni izjema: izvajalec
   ga pred zagonom razširi v konkretne id-je in šele ti so dovoljeni.
2. **Zahteva potrditev** — orodje, ki spreminja stanje (pošiljanje e-pošte,
   dodajanje/brisanje dogodkov, pisanje v spomin). V `dry-run` se ne izvede
   nikoli, v `apply` vedno zahteva tvojo potrditev, v `yolo` se izvede brez nje.
   `yolo` je edina izjema in je ni mogoče zapisati drugače kot z načinom samim:
   ni polja, ki bi jo dodalo, in ni drugega načina, ki bi jo vklopil.
3. **Dejansko potrditev** — tvoja odločitev ob konkretnem klicu. Vezana je na
   predlagano dejanje (kaj, kje, kdaj), ne na orodje na splošno: »da« za en
   dogodek ne odobri drugega. Shrani jo izvajalec. V `yolo` tega koraka ni.

Če kanala za potrditve (Telegram) še ni, se `apply` obnaša kot `dry-run` za
pisanje: mutirajoča dejanja ostanejo predlogi. Enako velja za `yolo`, ki je
zaradi izklopljenega stikala degradiran v `apply` — tudi tak zagon torej ne
spremeni ničesar sam, kar mora povedati v poročilu.

---

## 8. Pogoji za izvedbo (validacija pred vsakim zagonom)

Izvajalec izvede workflow **samo**, če velja vse našteto. Če kateri koli pogoj
odpove, workflowa **ne izvede**, ampak zabeleži in sporoči razlog.

1. Zapis je v kategoriji `workflows`.
2. Nosi oznako `executable`.
3. Ni arhiviran (arhiviran = pavza).
4. Telo vsebuje natanko en konfiguracijski blok in ta se parsira.
5. Vrednosti polj so veljavne (`mode` znan, `schedule` veljaven cron,
   `timezone` veljavno IANA ime).
6. Navodila za blokom so neprazna in ne vsebujejo oznak osnutka (`TODO`,
   `FIXME`, `…`, `<placeholder>`). Oznake osnutka se iščejo v **celem telesu**,
   tudi v vrednostih konfiguracijskega bloka: `<model-id>`, ki je ostal v bloku,
   je enako osnutek kot `TODO` v navodilih.
7. Vsa orodja iz `allowed_tools` se razrešijo v Tool Registryju in so v tem
   okolju dovoljena. Žolček (`*`, `calendar.*`) se razreši ob **dejanskem** registru
   tega zagona; če se ne razreši v nobeno orodje, je to napaka in zagon se
   preskoči (razdelek 9).
8. Če je predvideno mutirajoče dejanje: na voljo je potrditev uporabnika (ali je
   izvajalec v načinu, ki sprememb ne izvaja, ali je definicija v `yolo` **in**
   ima izvajalec vklopljeno svoje stikalo iz razdelka 7).
9. Definicija ni v nasprotju z varnostnimi pravili iz razdelka 10.

`yolo` ne sprošča nobenega drugega pogoja: kategorija, oznaka, arhiviranje,
konfiguracijski blok, polja iz razdelka 4 in varnostna pravila iz razdelka 10
veljajo enako.

Neveljavna, nepopolna ali dvoumna definicija **nikoli** ne povzroči spremembe v
zunanjem sistemu.

---

## 9. Ravnanje ob napakah in dvoumnostih

| Situacija | Ravnanje |
| --- | --- |
| Ni konfiguracijskega bloka | Ni workflow — samo zapisek. Izvajalec ga ignorira (nikoli ne javi napake). |
| Blok se ne parsira, `mode` neznan, cron neveljaven, `timezone` neveljaven | Ne izvedi; javi, kaj v definiciji je treba popraviti. |
| Navodila dvoumna ali manjkajo podatki za odločitev | Ne ugibaj: pripravi predlog in vprašanje za uporabnika. |
| Orodje ni v Tool Registryju ali ni dosegljivo | Tega koraka ne izvedi; poročaj **delni** rezultat in napako. Nikoli ne simuliraj rezultata. |
| Vir podatkov nedosegljiv (e-pošta, koledar) | Ne nadomeščaj podatkov z ugibanjem; javi napako in nadaljuj z drugimi koraki, ki so mogoči. |
| Orodje ni na `allowed_tools` | Zavrni klic, tudi če bi bil korak smiseln. |
| Mutirajoče orodje v načinu `yolo` | Izvedi in zabeleži: orodje, argumente (brez skrivnosti) in ključ idempotence. |
| `mode: yolo`, stikalo izvajalca izklopljeno | Izvedi kot `apply` in v poročilu povej, da je bil način degradiran — nikoli tiho. |
| Žolček v `allowed_tools` se ne razširi v nobeno orodje | Ne izvedi; javi, kateri žolček ni ujel ničesar (najverjetneje strežnik ni dosegljiv). Ne razumej tega kot "dovoljeno nič". |
| Enako dejanje je bilo že izvedeno (isti ključ idempotence) | Ne ponovi; javi, da je bilo že izvedeno. |
| Definicija se med izvajanjem spremeni | Trenutni zagon se zaključi po starih pravilih; nova velja od naslednjega zagona. |

---

## 10. Varnostna načela

- **Privzeto izklopljeno:** nov workflow nima oznake `executable`; ko jo dobi,
  teče v `dry-run`, dokler `mode` ni zavestno spremenjen — in `yolo` ne teče,
  dokler ni zavestno vklopljeno tudi stikalo v okolju izvajalca.
- **LLM si ne podeli dovoljenj.** Oznake ne more dodati, aktiviranega workflowa
  ne more spremeniti (razdelek 5), mutirajoča dejanja pa odobri uporabnik — v
  `yolo` tako, da je način zapisal in stikalo vklopil on, ne agent.
- **Preverjanje je v izvajalcu, ne v navodilih.** Izvajalec preveri dovoljena
  orodja, argumente in pravice za **vsak** klic. Navodila workflowa so
  uporabniška konfiguracija, ne dovoljenje za obhod pravil.
- **Žolček je edina širitev in mora biti vidna.** `*` in `<strežnik>.*` razširi
  izvajalec ob vsakem klicu; ker tak seznam zajame tudi orodja, ki jih strežnik
  ponudi pozneje, mora poročilo vsakega takega zagona povedati, na koliko orodij
  se je žolček razširil in koliko od njih spreminja stanje (z imeni). V načinu
  `yolo` to velja tudi za destruktivna orodja — brez tega bi bilo "dovoljeno vse"
  nevidno.
- **`yolo` je človeška poteza, dvakrat.** Način je v telesu, telo pa lahko v
  admin UI piše samo človek (razdelek 5); stikalo je v okolju izvajalca. Zato
  `yolo` ne potrebuje ločene shrambe odobritev — meja je ista kot pri oznaki:
  agent ga ne more ne zapisati ne vklopiti.
- **Stikalo je globalni preklic.** Ena vrednost v okolju izvajalca ustavi vsak
  `yolo` zagon na instanci. Izvajalec ob zagonu zabeleži, da je stikalo
  vklopljeno, sicer bi instanca tiho tekla v najširšem načinu. Stikalo pripada
  izvajalcu: `AIORCH_ENABLE_YOLO` ni okoljska spremenljivka MemoryDowna in
  MemoryDown ga ne bere — kdor ga vpiše v njegov `.env`, ne spremeni ničesar.
- **`yolo` ne odpravi nezaupanja vredne vsebine.** E-pošta, dokumenti in splet
  ostajajo podatki, ne navodila; v `yolo` to velja še strožje, ker ni človeka, ki
  bi vsako dejanje potrdil. Workflow, ki iz vsebine sporočila pošilja poverilnice
  ali skrivnosti, je v nasprotju s tem razdelkom in z razdelkom 3.
- **Vsak zagon v `yolo` mora biti viden.** Poročilo navede vsako izvedeno
  mutirajoče dejanje z orodjem, argumenti (brez skrivnosti) in ključem
  idempotence.
- **`yolo` ne razširi `allowed_tools`.** Kar ni na seznamu, ostane zavrnjeno tudi
  v `yolo`; brez seznama sme workflow tudi v `yolo` le brati.
- **E-pošta, dokumenti in druga zunanja vsebina so nezaupanja vredni podatki.**
  Nikoli niso navodila, ki bi spremenila pravila, razkrila skrivnosti ali
  sprožila dejanja izven naloge workflowa.
- **Potrditev in preprečevanje podvajanja** veljata za dodajanje dogodkov,
  pošiljanje e-pošte, brisanje in vsako drugo pomembno dejanje: pred pisanjem se
  preveri obstoječe stanje, vsako dejanje se zabeleži s ključem idempotence.
- **Brez skrivnosti v definicijah in logih.** Vsebina e-pošte se ne logira v
  celoti; žetoni, gesla in ključi se ne logirajo nikoli.
- **Delni neuspeh ni uspeh.** Izvajalec poroča, kaj je uspelo, kaj ne in zakaj.

---

## 11. Postopek: od osnutka do upokojitve

1. **Osnutek** — v admin UI odpreš *New memory*, izbereš kategorijo `workflows`
   in telo se prednapolni s predlogo (razdelek 3). Izpolniš `Namen`, `Navodila`
   in (če je treba) `schedule` ter `llm_*`. Osnutek lahko zapiše tudi agent
   (`remember` v `workflows`, **brez** oznake `executable`; to je navaden
   zapisek).
2. **Aktivacija** — v admin UI dodaš oznako `executable`. Dokler je v telesu še
   `TODO` ali placeholder, ti verdikt pove, da izvajalec definicijo zavrne.
   Oznaka je dovoljena samo v `workflows` (razdelek 5). Od tu izvajalec
   definicijo vidi.
3. **Preverjanje** — workflow nekaj zagonov teče v `dry-run`: bere in poroča.
   Preveri, da je povzetek uporaben in da predlogi nimajo smisla le navidezno.
4. **Dovolitev pisanja** — v admin UI dodaš v `allowed_tools` orodja, ki
   spreminjajo stanje, in spremeniš `mode: apply`. Od tu so spremembe možne,
   vsaka pa zahteva tvojo potrditev.
5. **Brez potrditve (`yolo`)** — šele ko `apply` nekaj zagonov deluje
   predvidljivo, v admin UI spremeniš `mode: yolo` **in** v okolju izvajalca
   vklopiš `AIORCH_ENABLE_YOLO=true`. Dva koraka, ne eden: način brez stikala
   teče kot `apply`. Od tu izvajalec izvaja vsa orodja z `allowed_tools` brez
   vprašanja; edina meja ostane `allowed_tools`. Vrni se tako, da način spremeniš
   nazaj na `apply` ali `dry-run`, izklopiš stikalo ali arhiviraš zapis.
6. **Pavza** — arhiviraj zapis (checkbox *Archived*) ali odstrani oznako.
   Arhiviran workflow se ne izvaja, vendar ostane berljiv.
7. **Sprememba** — urejaš v admin UI. Agent ne more urejati aktiviranega
   workflowa; spremembo lahko predlaga kot nov osnutek v `workflows` ali `notes`,
   ti pa jo preneseš v definicijo.
8. **Upokojitev** — odstrani oznako (postane navaden zapisek) ali izbriši zapis
   v admin UI (gre v `.trash/`, kjer ga je mogoče obnoviti).

Za aktivacijo ni dodatnih korakov v izvajalcu: ni registracije, ni odobritve, ni
datotek z dovoljenji. Definicija v Markdownu je celotna resnica. Edina
izvajalčeva nastavitev je njegovo stikalo za `yolo` (korak 5), ki pa ne odloča o
posamezni definiciji, ampak o tem, ali sme na tej instanci katera koli teči brez
potrditev.

---

## 12. Kaj ta standard ne ureja (in kaj je naslednja faza)

Ne ureja: implementacije izvajalca, izbire LLM ponudnika, Tool Registryja,
Telegram integracije, poročanja, sheme SQLite. To so odločitve izvajalca, ki jih
ta oblika definicij omogoča brez ugibanja.

Zavestno **ni** v v1 in naj se ne doda brez prave potrebe:

- verzioniranje formata (`standard: 1`). Dokler je oblika ena, je nepotrebno;
  doda se, če se kdaj nezdružljivo spremeni.
- dodatna polja v konfiguracijskem bloku (`limits`, `report`, …). Izvajalec ima
  svoje privzete omejitve in naj jih uveljavlja neodvisno od definicije.

**Kar je bilo tu prej in je zdaj v standardu:** samodejno odobritev
(`auto_approve`) izraža `mode: yolo` skupaj s stikalom izvajalca (razdelek 7) —
dva človeška dejanja, ki ju definicija ne more opraviti sama. Ostaja pa pravilo,
da polja `enabled`, `require_confirmation` in `auto_approve` ne obstajajo, in da
je meja seznam, kot je **zapisan**: žolček razširi izvajalec ob klicu (razdelek 4).

---

## 13. Stanje preverjanja

**Izmerjeno (testi v repozitoriju, CI na PHP 8.2, 8.3 in 8.4):**

- `tests/unit-tests.php`: oblika predloge (prvi blok je `yaml`, brez
  frontmatterja in brez H1, znana polja, `TODO` in placeholder za model),
  parser konfiguracijskega bloka (neznano polje, neveljaven `mode`, 4-poljski in
  izven-obsežni cron, neveljaven časovni pas, prazen seznam, manjkajoča navodila,
  komentarji in narekovaji), oznaka `executable` proti seznamu človeških oznak,
  način `yolo` kot znan način in skalarna oblika `allowed_tools`.
- `tests/run-tests.php` (cela veriga: health → odkrivanje → OAuth → token →
  MCP `initialize` → `tools/list` → klici orodij → admin UI): oznaka je zavrnjena
  povsod, kjer bi jo agent lahko nastavil, zapis z oznako prek MCP ni ne
  spremenljiv ne brisljiv, branje ostane odprto, zapisek v `workflows` ostane
  pisljiv, admin UI definicijo še vedno ureja.
- Fenced `yaml` blok v telesu preživi `create` → `read` → `update` → `read`.
- Oznaka je dovoljena samo v `workflows`: zavrnjeno je označevanje drugje in
  premik aktiviranega workflowa drugam.
- Zapis brez naslova ne dobi več praznega naslova `#`: to je nekoč potisnilo
  telo navzdol in hkrati naredilo naslednjo vrstico za naslov (pri predlogi:
  komentar v bloku).
- Verdikt (kaj bi izvajalec zavrnil) se izračuna ob shranjevanju in ob branju,
  tako v API-ju kot na strani.
- `mode: yolo` je znan način in definicija z njim ni označena kot neveljavna;
  skalarna oblika `allowed_tools` šteje kot enoelementni seznam; žolček se v
  MemoryDownu ne razširja — ostane dobesedno besedilo, ker razširitev naredi
  izvajalec.

**Ni izmerjeno:**

- Stikala izvajalca (`AIORCH_ENABLE_YOLO`) MemoryDown ne more preizkusiti: ni
  njegovo okolje in ga ne bere. Kako se `yolo` obnaša brez njega, je stvar
  izvajalca.
- Izvajalec ne obstaja. Pogoji iz razdelka 8 so tu **opozorilo**, ne
  uveljavljanje: MemoryDown shrani tudi nepopolno definicijo, ker je osnutek
  normalno stanje (razdelek 11).
- Dejanski vzporedni dostop, veliki dokumenti, YAML zunaj ploske podmnožice iz
  razdelka 4 (gnezdenje, več dokumentov, sidra).
- Kako bo izvajalec razreševal `llm_provider` in `llm_model`.

**Kako se zapre:** ko obstaja C# `WorkflowProvider`, se doda test, ki definicijo
ustvari prek živega MCP, jo prebere, poskusi spremeniti (mora biti zavrnjeno) in
jo nato spremeni v admin UI. Ta korak je zadnja odprta točka tega standarda.

**Kdo kaj uveljavlja:** oznako in kategorijo uveljavlja MemoryDown (to je meja
zaupanja, razdelek 5). Vse ostalo iz razdelka 8 uveljavlja šele izvajalec;
preverjanje v admin UI je informacija za uporabnika, ne varovalka. Enako velja za
branje prek API-ja: dostop do definicije ni dovoljenje za izvedbo.

---

## 14. Povzetek v enem odstavku

Workflow je Markdown zapis v `workflows` z oznako `executable`, ki jo lahko doda
samo človek v admin UI — in samo v tej kategoriji. Njegovo telo se začne s prvim
fenced `yaml` blokom (`mode`, `schedule`, `timezone`, `allowed_tools`,
`llm_provider`, `llm_model`), za njim so navodila v naravnem jeziku; telo za nov
zapis prednapolni admin UI. Z oznako je zapis prek MCP samo za branje, zato ga
agent ne more ne ustvariti ne spremeniti, verdikt v UI pa ti pove, kaj bi
izvajalec zavrnil. Privzeto teče v `dry-run` (samo bere in poroča), pisanje
omogoči `mode: apply`, kjer vsako mutirajoče dejanje potrdi uporabnik, `mode:
yolo` pa izvede vsa orodja z `allowed_tools` takoj, brez predloga in brez
potrditve — a le, če ima izvajalec vklopljeno svoje stikalo
(`AIORCH_ENABLE_YOLO=true`), tako da tudi `yolo` odobrita dva človeška dejanja.
Pavza je arhiviranje. Izvajalec sme izvesti le definicijo, ki prestane vse pogoje
iz razdelka 8 — v nasprotnem primeru javi razlog in ne spremeni ničesar.
