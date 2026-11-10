<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\TradePost;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Formulaire de publication d'un trade (CSRF actif par défaut).
 */
final class TradePostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('images', FileType::class, [
                'label' => 'Captures (1 à 3)',
                'mapped' => false,
                'multiple' => true,
                'help' => 'PNG, JPEG ou WebP, 4 Mo au plus chacune. Masquez votre nom, votre numéro de compte et votre solde.',
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp'],
                'constraints' => [
                    new Count(min: 1, max: 3, minMessage: 'Ajoutez au moins une capture.', maxMessage: 'Trois captures au maximum.'),
                    new All([new File(maxSize: '4M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp'], mimeTypesMessage: 'Formats acceptés : PNG, JPEG ou WebP.')]),
                ],
            ])
            ->add('instrument', TextType::class, [
                'label' => 'Instrument',
                'attr' => ['placeholder' => 'NQ, EUR/USD, BTC…', 'maxlength' => 40],
                'constraints' => [
                    new NotBlank(message: 'Indiquez l\'instrument.'),
                    new Length(max: 40),
                    new Regex(pattern: '/^[\p{L}\p{N} .\/\-+&]+$/u', message: 'Lettres, chiffres et / - . + & uniquement.'),
                ],
            ])
            ->add('timeframe', ChoiceType::class, [
                'label' => 'Unité de temps',
                'choices' => array_combine(TradePost::UNITES, TradePost::UNITES),
            ])
            ->add('direction', ChoiceType::class, [
                'label' => 'Sens',
                'choices' => ['Achat' => 'achat', 'Vente' => 'vente'],
                'expanded' => true,
            ])
            ->add('resultR', NumberType::class, [
                'label' => 'Résultat en R (facultatif)',
                'required' => false,
                'scale' => 2,
                'help' => 'Gain ou perte en multiples du risque pris : +2 = deux fois le risque gagné, −1 = stop touché. Vide si le trade est en cours.',
                'attr' => ['placeholder' => '2 ou -1'],
                'constraints' => [new Range(min: -20, max: 50, notInRangeMessage: 'Entre −20 R et +50 R.')],
            ])
            ->add('theme', ChoiceType::class, [
                'label' => 'Thème',
                'choices' => array_combine(array_map(static fn (array $t): string => $t[0], TradePost::THEMES), array_keys(TradePost::THEMES)),
            ])
            ->add('lesson', TextareaType::class, [
                'label' => 'Pourquoi ce trade, et ce que vous en retenez',
                'attr' => ['rows' => 6, 'maxlength' => 1500, 'placeholder' => 'Le contexte, ce qui a déclenché l\'entrée, où était le stop, ce qui s\'est passé et ce que vous referiez autrement.'],
                'constraints' => [
                    new NotBlank(message: 'Expliquez votre trade : c\'est ce qui le rend utile aux autres.'),
                    new Length(min: 40, max: 1500, minMessage: 'Quelques phrases au moins (40 caractères).'),
                ],
            ])
            ->add('rules', CheckboxType::class, [
                'mapped' => false,
                'label' => 'J\'ai lu les règles de la communauté et masqué mes informations personnelles',
                'constraints' => [new IsTrue(message: 'Vous devez accepter les règles.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TradePost::class]);
    }
}
