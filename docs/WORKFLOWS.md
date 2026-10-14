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

````markdown
---
type: workflows
tags:
  - executable
  - email
  - calendar
created: 2026-10-15T09:00:00Z
updated: 2026-10-15T09:00:00Z
source: chatgpt
id: preverjanje-e-poste-in-koledarja-1a2b3c
---

# Preverjanje e-pošte in koledarja

```yaml
mode: dry-run
schedule: "0 7,12 * * *"
timezone: Europe/Ljubljana
allowed_tools:
  - calendar.search_emails
  - calendar.get_email
  - calendar.get_events
  - memory.search_memory
  - memory.read_memory
  - calendar.create_event
```

## Namen

Preglej nova sporočila od zadnjega uspešnega zagona in poišči dogodke, ki bi
morali biti v koledarju, pa jih tam ni.

## Navodila

1. Preglej relevantna sporočila v INBOX-u od zadnjega uspešnega zagona.
2. Za vsak zaznan dogovor preveri, ali dogodek v koledarju že obstaja.
3. Če dogodek manjka in so datum, ura in vsebina dovolj jasni, ga predlagaj
   (v načinu `apply` ga dodaj, če to potrdim).
4. Če je karkoli nejasno ali je možen dvojnik, pripravi predlog namesto
   ugibanja.
5. Na koncu poročaj: kaj je pomembno, kaj predlagaš, kaj je bilo izvršeno in
   katere odločitve potrebujem od tebe.
````

Pravila oblike:

- Naslov (H1) je prvi naslov telesa. MemoryDown ga vrne ločeno, telo pa se začne
  s konfiguracijskim blokom.
- Konfiguracijski blok je **prvi fenced blok v telesu** z oznako jezika `yaml`.
  Natanko eden na dokument.
- Za blokom so navodila v naravnem jeziku (slovensko ali angleško), lahko tudi
  z vmesnimi naslovi `##`.
- Dokument ne vsebuje skrivnosti (gesel, žetonov). Navodila naj jih ne
  zahtevajo: izvajalec jih bere iz svoje konfiguracije.

---

## 4. Polja

| Polje | Tip | Privzeto (če manjka) | Pomen |
| --- | --- | --- | --- |
| `mode` | `dry-run` \| `apply` | `dry-run` | `dry-run`: samo bralna orodja, vsaka predvidena sprememba se poroča. `apply`: dovoljene so spremembe, vsako mutirajoče orodje zahteva tvojo potrditev ob klicu. |
| `schedule` | cron (5 polj) | odsotno = samo ročni zagon | Kdaj se izvede, v času `timezone`. |
| `timezone` | IANA ime | `Europe/Ljubljana` | Časovni pas urnika in poročil. |
| `allowed_tools` | seznam id-jev | odsotno = samo bralna orodja | Katera orodja workflow sploh sme klicati. Kar ni na seznamu, izvajalec zavrne. |

Dodatna pravila:

- **Neznana polja** izvajalec ignorira in o tem **opozori v poročilu**. Neznano
  polje nikoli ne razširi dovoljenj.
- **Polja `enabled`, `require_confirmation` in `auto_approve` ne obstajajo.**
  `enabled` nadomeščata oznaka in arhiviranje; potrditev je lastnost orodja in
  odločitev uporabnika, ne polje v definiciji; samodejno odobritev bo dodana
  (če sploh) kot odločitev izvajalca, vezana na vsebino definicije.
- Izvajalec naj za razvrščanje orodij med bralna in mutirajoča uporabi oznake
  orodij iz Tool Registryja (MCP orodja nosijo `readOnlyHint`), ne lastnega
  seznama imen.

---

## 5. Oznaka `executable` je rezervirana in človeška

- Oznako `executable` **lahko doda samo admin UI**. `remember` in
  `update_memory` prek MCP jo zavrneta.
- Vsak zapis, ki oznako nosi, je prek MCP **samo za branje**: `update_memory`
  ne more spremeniti telesa, oznak, kategorije (premika) ali arhiviranja,
  `forget_memory` pa ga ne more izbrisati. Branje (`recall`, `search_memory`,
  `read_memory`, `list_memory`) je vedno dovoljeno.
- Zato lahko agent workflow **predlaga** (zapiše osnutek brez oznake), nikoli pa
  ga ne more **aktivira** ali prepisati.

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

## 7. Načina delovanja in potrditve

| | `dry-run` | `apply` |
| --- | --- | --- |
| bralna orodja | izvede | izvede |
| mutirajoča orodja | **nikoli** — poroča predlog | izvede jih **samo po tvoji potrditvi ob klicu** |
| kaj dobiš | povzetek, predloge, vprašanja | povzetek + izvedena dejanja |

Trije pojmi, ki jih ne gre mešati:

1. **Dovoljeno** — orodje je na seznamu `allowed_tools`. To je zgornja meja.
2. **Zahteva potrditev** — vsako orodje, ki spreminja stanje (pošiljanje
   e-pošte, dodajanje/brisanje dogodkov, pisanje v spomin). V v1 **vsako**
   mutirajoče orodje zahteva potrditev; ni izjeme, ki bi jo definicija lahko
   zapisala sama.
3. **Dejansko potrditev** — tvoja odločitev ob konkretnem klicu. Vezana je na
   predlagano dejanje (kaj, kje, kdaj), ne na orodje na splošno: »da« za en
   dogodek ne odobri drugega. Shrani jo izvajalec.

Če kanala za potrditve (Telegram) še ni, se `apply` obnaša kot `dry-run` za
pisanje: mutirajoča dejanja ostanejo predlogi.

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
   `FIXME`, `…`, `<placeholder>`).
7. Vsa orodja iz `allowed_tools` se razrešijo v Tool Registryju in so v tem
   okolju dovoljena.
8. Če je predvideno mutirajoče dejanje: na voljo je potrditev uporabnika (ali je
   izvajalec v načinu, ki sprememb ne izvaja).
9. Definicija ni v nasprotju z varnostnimi pravili iz razdelka 10.

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
| Enako dejanje je bilo že izvedeno (isti ključ idempotence) | Ne ponovi; javi, da je bilo že izvedeno. |
| Definicija se med izvajanjem spremeni | Trenutni zagon se zaključi po starih pravilih; nova velja od naslednjega zagona. |

---

## 10. Varnostna načela

- **Privzeto izklopljeno:** nov workflow nima oznake `executable`; ko jo dobi,
  teče v `dry-run`, dokler `mode` nista zavestno spremenjena.
- **LLM si ne podeli dovoljenj.** Oznake ne more dodati, aktiviranega workflowa
  ne more spremeniti (razdelek 5), mutirajoča dejanja pa odobri uporabnik.
- **Preverjanje je v izvajalcu, ne v navodilih.** Izvajalec preveri dovoljena
  orodja, argumente in pravice za **vsak** klic. Navodila workflowa so
  uporabniška konfiguracija, ne dovoljenje za obhod pravil.
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

1. **Osnutek** — zapišeš ga sam (admin UI) ali ga predlaga agent (`remember` v
   `workflows`, **brez** oznake `executable`; to je navaden zapisek).
2. **Aktivacija** — v admin UI dodaš oznako `executable`. Dodaj tudi
   konfiguracijski blok z `mode: dry-run` (privzeto) in `schedule`. Od tu
   izvajalec definicijo vidi.
3. **Preverjanje** — workflow nekaj zagonov teče v `dry-run`: bere in poroča.
   Preveri, da je povzetek uporaben in da predlogi nimajo smisla le navidezno.
4. **Dovolitev pisanja** — v admin UI dodaš v `allowed_tools` orodja, ki
   spreminjajo stanje, in spremeniš `mode: apply`. Od tu so spremembe možne,
   vsaka pa zahteva tvojo potrditev.
5. **Pavza** — arhiviraj zapis (checkbox *Archived*) ali odstrani oznako.
   Arhiviran workflow se ne izvaja, vendar ostane berljiv.
6. **Sprememba** — urejaš v admin UI. Agent ne more urejati aktiviranega
   workflowa; spremembo lahko predlaga kot nov osnutek v `workflows` ali `notes`,
   ti pa jo preneseš v definicijo.
7. **Upokojitev** — odstrani oznako (postane navaden zapisek) ali izbriši zapis
   v admin UI (gre v `.trash/`, kjer ga je mogoče obnoviti).

Za aktivacijo ni dodatnih korakov v izvajalcu: ni registracije, ni odobritve, ni
datotek z dovoljenji. Definicija v Markdownu je celotna resnica.

---

## 12. Kaj ta standard ne ureja (in kaj je naslednja faza)

Ne ureja: implementacije izvajalca, izbire LLM ponudnika, Tool Registryja,
Telegram integracije, poročanja, sheme SQLite. To so odločitve izvajalca, ki jih
ta oblika definicij omogoča brez ugibanja.

Zavestno **ni** v v1 in naj se ne doda brez prave potrebe:

- `auto_approve` / samodejno izvajanje mutirajočih dejanj brez potrditve. Če se
  kdaj doda, mora biti to odločitev **izvajalca**, podeljena človeku, vezana na
  zgoščeno vrednost odobrene definicije — tako da sprememba navodil ali
  `allowed_tools` zahteva novo odobritev.
- verzioniranje formata (`standard: 1`). Dokler je oblika ena, je nepotrebno;
  doda se, če se kdaj nezdružljivo spremeni.
- dodatna polja v konfiguracijskem bloku (`limits`, `report`, …). Izvajalec ima
  svoje privzete omejitve in naj jih uveljavlja neodvisno od definicije.

---

## 13. Stanje preverjanja

Standard je treba brati s to razliko:

**Izmerjeno (izvedeno nad resnično kodo iz `src/`)** — ker je lokalni PHP 8.0 in
projekt zahteva 8.2, je bil test izveden nad kopijo, v kateri sta bila mehansko
nevtralizirana le `readonly` in privzeti `new` (nič drugega):

- fenced `yaml` blok v telesu preživi `create` → `read` → `update` (tudi `update`
  brez telesa) → `read` nespremenjen;
- eno shranjevanje **izbriše** neznane ključe iz frontmatterja (`enabled:`,
  `confirmation:`), oznaka `executable` pa pri tem **ostane** — kar je razlog za
  pravilo iz razdelka 5;
- poizvedba `list(category: workflows, tag: executable, active)` najde definicijo,
  neobstoječa oznaka ne ujame ničesar, arhiviranje izloči workflow iz aktivnih in
  hkrati ohrani oznako.

**Ni izmerjeno (samo pregledano v kodi):**

- vedenje `MemoryTools` prek MCP (zavrnitev oznake v `remember`/`update_memory`/
  `forget_memory`): koda je pregledana, novi testi v `tests/run-tests.php` pa se
  izvedejo šele na PHP ≥ 8.2 (CI ali gostovanje). Lokalno jih ni mogoče pognati.
- vedenje admin UI nad definicijo z autosave (sklep iz kode, ne preizkus).
- dejanski vzporedni dostop, veliki dokumenti, robni primeri YAML.

**Kako se zapre:** ko obstaja C# `WorkflowProvider`, se doda test, ki definicijo
ustvari prek živega MCP, jo prebere, poskusi spremeniti (mora biti zavrnjeno) in
jo nato spremeni v admin UI. Ta korak je zadnja odprta točka tega standarda.

---

## 14. Povzetek v enem odstavku

Workflow je Markdown zapis v `workflows` z oznako `executable`, ki jo lahko doda
samo človek v admin UI. Njegovo telo vsebuje prvi fenced `yaml` blok s `mode`,
`schedule`, `timezone` in `allowed_tools` ter navodila v naravnem jeziku. Z
oznako je zapis prek MCP samo za branje, zato ga agent ne more ne ustvariti ne
spremeniti. Privzeto teče v `dry-run` (samo bere in poroča), pisanje omogoči
`mode: apply`, kjer vsako mutirajoče dejanje potrdi uporabnik. Pavza je
arhiviranje. Izvajalec sme izvesti le definicijo, ki prestane vse pogoje iz
razdelka 8 — v nasprotnem primeru javi razlog in ne spremeni ničesar.
