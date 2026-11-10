<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Task;
use App\Entity\TaskType;
use App\Enum\RecurrenceKind;
use App\Enum\TaskPriority;
use App\Repository\TaskTypeRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/** A household member adding a chore. Reward is capped for non-admins. 
 * @extends AbstractType<Task>
 */
final class UserTaskType extends AbstractType
{
    public const USER_MAX_REWARD = 100;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $max = $options['is_admin'] ? Task::MAX_REWARD : self::USER_MAX_REWARD;
        $builder
            ->add('title', TextType::class)
            ->add('description', TextareaType::class, ['required' => false])
            ->add('type', EntityType::class, [
                'class' => TaskType::class,
                'choice_label' => 'name',
                'query_builder' => static fn (TaskTypeRepository $r) => $r->createQueryBuilder('t')->andWhere('t.isActive = true')->orderBy('t.name'),
                'placeholder' => 'Choose a type',
            ])
            ->add('priority', EnumType::class, ['class' => TaskPriority::class, 'choice_label' => static fn (TaskPriority $p) => $p->label()])
            ->add('rewardValue', IntegerType::class, [
                'required' => false,
                'label' => 'Reward value',
                'help' => \sprintf('Base points, 0–%d. Leave empty for the default.', $max),
                'constraints' => [new Range(min: 0, max: $max)],
            ])
            ->add('recurrenceKind', EnumType::class, [
                'class' => RecurrenceKind::class,
                'label' => 'Repeats',
                'choices' => [RecurrenceKind::OneOff, RecurrenceKind::Reusable],
                'choice_label' => static fn (RecurrenceKind $k) => $k->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Task::class, 'is_admin' => false]);
        $resolver->setAllowedTypes('is_admin', 'bool');
    }
}
