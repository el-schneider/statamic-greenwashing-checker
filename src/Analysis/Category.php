<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Analysis;

enum Category: string
{
    case GenericClaim = 'generic_claim';
    case OffsetNeutrality = 'offset_neutrality';
    case ScopeOverreach = 'scope_overreach';
    case UnofficialLabel = 'unofficial_label';
    case LegalRequirementAsFeature = 'legal_requirement_as_feature';
    case FuturePerformance = 'future_performance';
    case IrrelevantBenefit = 'irrelevant_benefit';
    case MisleadingEnvironmental = 'misleading_environmental';
    case DurabilityRepair = 'durability_repair';

    public function label(): string
    {
        return __('greenwashing-checker::messages.categories.'.$this->value);
    }

    public function severity(): Severity
    {
        return match ($this) {
            self::GenericClaim, self::OffsetNeutrality, self::ScopeOverreach,
            self::UnofficialLabel, self::LegalRequirementAsFeature => Severity::High,
            self::FuturePerformance, self::IrrelevantBenefit,
            self::MisleadingEnvironmental, self::DurabilityRepair => Severity::Medium,
        };
    }

    public function norm(): string
    {
        // The prompt uses the fixed EU core, never configurable display references.
        return __('greenwashing-checker::references.eu.'.$this->value, [], 'en');
    }

    public function definition(): string
    {
        return match ($this) {
            self::GenericClaim => 'Allgemeine Umweltaussage ohne klare, hervorgehobene Spezifizierung im selben Medium oder anerkannte hervorragende Umweltleistung (EU Ecolabel, Blauer Engel/ISO 14024 Typ I, höchste EU-Klasse). Beispiele: umweltfreundlich, nachhaltig, grün, öko, klimafreundlich, conscious, responsible, energieeffizient, biologisch abbaubar ohne Bedingungen. Eine bloße Recyclingangabe rechtfertigt kein pauschales nachhaltig.',
            self::OffsetNeutrality => 'Produktbezogene Aussage über neutrale, verringerte oder positive Treibhausgaswirkung, die auf Kompensation beruht: klimaneutral durch CO₂-Zertifikate, klimapositives Produkt dank Kompensation. Kompensation muss aus Text/Kontext hervorgehen; klimaneutral allein ist kein Beweis dafür. Unternehmensaussagen sind NICHT Nr. 4c.',
            self::ScopeOverreach => 'Umweltaussage über das gesamte Produkt oder Unternehmen, obwohl der Vorteil nur einen Teil betrifft. Beispiel: nachhaltiges Produkt, weil nur die Verpackung aus Recyclingpapier besteht; grünes Unternehmen, weil nur ein Standort Ökostrom nutzt. Eine ausdrücklich auf die Verpackung begrenzte Aussage ist kein scope_overreach.',
            self::UnofficialLabel => 'Nachhaltigkeitssiegel ohne Zertifizierungssystem oder öffentliche Festsetzung. Beispiele: unser hauseigenes Eco-Siegel, selbst vergebenes Green-Badge. Ein ausdrücklich zertifiziertes EU Ecolabel/Blauer Engel ist kein solches Siegel.',
            self::LegalRequirementAsFeature => 'Gesetzliche Pflicht wird als besondere Eigenschaft des Angebots beworben. Beispiele: FCKW-freies Haarspray als exklusiver Umweltvorteil, BPA-freie Lebensmittelkontaktmaterialien als besonderes Merkmal, Mikroplastikfreiheit bei ausdrücklich geltendem Verbot. Keine Pflicht ohne belastbaren Anhaltspunkt unterstellen.',
            self::FuturePerformance => 'Aussage über zukünftige Umweltleistung ohne öffentlichen, überprüfbaren und zeitgebundenen Umsetzungsplan mit regelmäßiger unabhängiger Prüfung. Beispiele: bis 2030 klimaneutral, net zero by 2040 ohne Plan. Nur Absicht/Versprechen genügt nicht.',
            self::IrrelevantBenefit => 'Irrelevanter Vorteil, der nicht aus einer Eigenschaft des Produkts stammt. Beispiele: glutenfreies Mineralwasser als Umweltvorteil, papierfreies Shampoo rettet Bäume. Keine rein sachliche Inhaltsstoffangabe beanstanden.',
            self::MisleadingEnvironmental => 'Irreführende ökologische/soziale Aussage oder Kreislaufwirtschaftsbehauptung, auch unbelegte Vergleiche mit unklarer Bezugsbasis/Methode. Beispiele: 30 % weniger CO₂ ohne Vergleichsbasis, 100 % recycelbar trotz untrennbarem Verbundmaterial. Nicht pauschal Beweise für jede konkrete, plausible technische Angabe verlangen.',
            self::DurabilityRepair => 'Falsche Aussage zur Haltbarkeit oder Reparierbarkeit. Beispiele: hält ein Leben lang trotz bekannter kurzer Lebensdauer; vollständig reparierbar trotz verklebter, nicht ersetzbarer Teile. Fehlender Nachweis allein beweist keine Falschheit; Widersprüche im Kontext beachten.',
        };
    }
}
