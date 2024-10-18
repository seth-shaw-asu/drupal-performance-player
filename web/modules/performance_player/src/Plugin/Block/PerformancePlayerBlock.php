<?php

declare(strict_types=1);

namespace Drupal\performance_player\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a performance_player block.
 *
 * @Block(
 *   id = "performance_player",
 *   admin_label = @Translation("performance_player"),
 *   category = @Translation("Custom"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
final class PerformancePlayerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_url_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->getContextValue('node');
    if (!isset($node)) {
      return [];
    }

    $build = [];
    if ($node->hasField('field_program') && !$node->get('field_program')->isEmpty()) {
      foreach ($node->get('field_program') as $program) {
        $build[] = $program->view(['type' => 'entity_reference_entity_view', 'label' => 'visually_hidden']);
      }
    }
    // Build player.
    // See https://www.geeksforgeeks.org/create-a-music-player-using-javascript
    if ($node->hasField('field_tracks') && !$node->get('field_tracks')->isEmpty()) {
      // The JSON version that will load.
      $tracks_metadata = [];
      // The render array version for the displayed playlist.
      $tracks_render_array = [
        '#type' => 'container',
        '#attributes' => ['class' => ['tracks-playlist']],
      ];

      foreach ($node->get('field_tracks') as $delta => $track) {
        // Build the track display skeleton.
        // Considered using getID3, but that requires a local copy when ours
        // will be stored on S3. That is better stored as a value on the
        // media done as a microservice, but that is a separate work item.
        $track_render_array = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['tracks-playlist-track'],
            'id' => "tracks-playlist-track-{$delta}",
          ],
          'title' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['track-playlist-track-title']],
          ],
          'composers' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['track-playlist-track-composers']],
          ],
          'download' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['track-playlist-track-download']],
          ],
        ];
        // Now add each metada element to the JSON and render array.
        if (!($track->entity->get('field_title')?->isEmpty())) {
          $tracks_metadata[$delta]['title'] = $track->entity->field_title->value;
          $track_render_array['title'][] = $track->entity->field_title->view(['label' => 'visually-hidden']);
        }
        if (!($track->entity->get('field_composer')?->isEmpty())) {
          foreach ($track->entity->get('field_composer') as $composer) {
            $tracks_metadata[$delta]['composers'][] = $composer->entity->label();
          }
          $track_render_array['composers'][] = $track->entity->get('field_composer')->view(['label' => 'visually-hidden']);
        }
        if (!($track->entity->get('field_audio')?->isEmpty())) {
          $tracks_metadata[$delta]['path'] = $this->fileUrlGenerator->generateAbsoluteString($track->entity->field_audio->entity->field_media_audio_file->entity->getFileUri());
          $track_render_array['download']['link'] = [
            '#type' => 'html_tag',
            '#tag' => 'a',
            '#attributes' => [
              'href' => $tracks_metadata[$delta]['path'],
            ],
            'icon' => [
              '#type' => 'html_tag',
              '#tag' => 'i',
              '#attributes' => [
                'class' => ['fa', 'fa-cloud-download-alt fa-2x'],
              ],
            ],
          ];
        }
        $tracks_render_array[] = $track_render_array;
      }

      // Add the task lists to the build render array
      // and build the actual player.
      if (!empty($tracks_metadata)) {
        $build[] = $tracks_render_array;
        $build['#attached']['drupalSettings']['performance_player']['track_list'] = $tracks_metadata;
        $build['#attached']['library'][] = 'performance_player/player';
        // Attached Font Awesome for testing.
        $build[] = [
          '#type' => 'html_tag',
          '#tag' => 'link',
          '#attributes' => [
            'rel' => 'stylesheet',
            'href' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.13.0/css/all.min.css',
          ],
        ];
        $build[] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['performance_player_player']],
          'details' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['details']],
            'now-playing' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['now-playing']],
              'text' => ['#plain_text' => "PLAYING x OF y"],
            ],
            'track-name' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['track-name']],
              'text' => ['#plain_text' => "Track Name"],
            ],
            'composers' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['composers']],
              'text' => ['#plain_text' => "Composer(s)"],
            ],
          ],
          'buttons' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['buttons']],
            'prev-track' => [
              '#type' => 'container',
              '#attributes' => [
                'class' => ['prev-track'],
                'onclick' => 'prevTrack()',
              ],
              'player-icon' => [
                '#type' => 'html_tag',
                '#tag' => 'i',
                '#attributes' => [
                  'class' => ['fa', 'fa-step-backward', 'fa-2x'],
                ],
                '#value' => '',
              ],
            ],
            'play-pause' => [
              '#type' => 'container',
              '#attributes' => [
                'class' => ['play-pause'],
                'onclick' => 'playpauseTrack()',
              ],
              'player-icon' => [
                '#type' => 'html_tag',
                '#tag' => 'i',
                '#attributes' => [
                  'class' => ['fa', 'fa-play-circle', 'fa-2x'],
                ],
                '#value' => '',
              ],
            ],
            'next-track' => [
              '#type' => 'container',
              '#attributes' => [
                'class' => ['next-track'],
                'onclick' => 'nextTrack()',
              ],
              'player-icon' => [
                '#type' => 'html_tag',
                '#tag' => 'i',
                '#attributes' => [
                  'class' => ['fa', 'fa-step-forward', 'fa-2x'],
                ],
                '#value' => '',
              ],
            ],
          ],
          'seek' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['slider_container']],
            'current-time' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['current-time']],
              'text' => ['#plain_text' => "00:00"],
            ],
            'seek-slider' => [
              '#type' => 'html_tag',
              '#tag' => 'input',
              '#attributes' => [
                'type' => 'range',
                'min' => '1',
                'max' => '100',
                'value' => '0',
                'class' => ['seek_slider'],
                'onchange' => "seekTo()",
              ],
            ],
            'total-duration' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['total-duration']],
              'text' => ['#plain_text' => "00:00"],
            ],

          ],
          'volume' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['slider_container']],
            'volume-down' => [
              '#type' => 'html_tag',
              '#tag' => 'i',
              '#attributes' => [
                'class' => ['fa', 'fa-volume-down'],
              ],
              '#value' => '',
            ],
            'volume-slider' => [
              '#type' => 'html_tag',
              '#tag' => 'input',
              '#attributes' => [
                'type' => 'range',
                'min' => '1',
                'max' => '100',
                'value' => '99',
                'class' => ['volume_slider'],
                'onchange' => "setVolume()",
              ],
            ],
            'volume-up' => [
              '#type' => 'html_tag',
              '#tag' => 'i',
              '#attributes' => [
                'class' => ['fa', 'fa-volume-up'],
              ],
              '#value' => '',
            ],
          ],
        ];
      }
    }
    return $build;
  }

}
