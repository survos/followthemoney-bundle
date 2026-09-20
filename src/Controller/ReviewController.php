<?php

declare(strict_types=1);

namespace Survos\FollowTheMoneyBundle\Controller;

use Survos\FollowTheMoney\Model;
use Survos\FtmResolver\{ResolutionProvider, ProviderException};
use Survos\FollowTheMoneyBundle\Review\{ReviewStore, Decision, ReviewConflict, EvidenceLinker};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ftm/review', priority: 100)]
final class ReviewController extends AbstractController
{
    public function __construct(private readonly ResolutionProvider $provider, private readonly ReviewStore $store, private readonly Model $model, private readonly EvidenceLinker $links, private readonly array $datasets, private readonly string $role, private readonly string $baseTemplate)
    {
    }
    private function authorize(string $dataset): void
    {
        $this->denyAccessUnlessGranted($this->role);
        if (!in_array($dataset, $this->datasets, true)) {
            throw $this->createNotFoundException('Dataset is not configured for review');
        }
    }
    #[Route('', name: 'ftm_review_home', methods: ['GET'])]
    public function home(): Response
    {
        $this->denyAccessUnlessGranted($this->role);
        return $this->render('@SurvosFollowTheMoney/home.html.twig', ['baseTemplate' => $this->baseTemplate,'datasets' => $this->datasets]);
    }
    #[Route('/{dataset}', name: 'ftm_review', methods: ['GET'])]
    public function review(string $dataset, Request $request): Response
    {
        $this->authorize($dataset);
        $q = trim($request->query->getString('q'));
        $id = $request->query->getString('id');
        $offset = max(0, min(9499, $request->query->getInt('offset')));
        $source = null;
        $search = null;
        $candidates = [];
        $relations = null;
        $error = null;
        $current = false;
        try {
            $catalog = $this->provider->catalog();
            $current = $this->current($dataset, $catalog);
            if ($id !== '') {
                $source = $this->store->source($dataset, $id);
                if ($source === null) {
                    throw $this->createNotFoundException('Import this source generation before reviewing it');
                }
                $entity = $this->model->fromArray($source['entity']);
                $result = $this->provider->match($dataset, $entity);
                foreach ($result->candidates as $candidate) {
                    if ($candidate->entity->id === $id) {
                        continue;
                    }
                    $target = $this->store->source($dataset, $candidate->entity->id);
                    $history = $this->store->history($dataset, $id, $candidate->entity->id);
                    $candidates[] = ['candidate' => $candidate,'target' => $target,'history' => $history,'pair' => $this->store->pair($dataset, $id, $candidate->entity->id),'revision' => $history[0]['revision'] ?? 0];
                }
                $relations = $this->provider->adjacent($id);
            } elseif ($q !== '') {
                $search = $this->provider->search($dataset, $q, $offset);
            }
        } catch (ProviderException $e) {
            $error = $e->getMessage();
        }
        return $this->render('@SurvosFollowTheMoney/review.html.twig', ['baseTemplate' => $this->baseTemplate,'dataset' => $dataset,'q' => $q,'offset' => $offset,'source' => $source,'search' => $search,'candidates' => $candidates,'relations' => $relations,'error' => $error,'current' => $current,'linker' => $this->links]);
    }
    #[Route('/{dataset}/decisions', name: 'ftm_review_report', methods: ['GET'])]
    public function report(string $dataset): Response
    {
        $this->authorize($dataset);
        $rows = $this->store->currentDecisions($dataset);
        foreach ($rows as &$row) {
            $row['snapshot'] = json_decode($row['snapshot'], true, flags:JSON_THROW_ON_ERROR);
        }
        return $this->render('@SurvosFollowTheMoney/report.html.twig', ['baseTemplate' => $this->baseTemplate,'dataset' => $dataset,'rows' => $rows,'version' => $this->store->version($dataset)]);
    }
    private function current(string $dataset, array $catalog): bool
    {
        if (!in_array($dataset, $catalog['current'] ?? [], true)) {
            return false;
        }
        foreach ($catalog['datasets'] ?? [] as $entry) {
            if ($entry['name'] === $dataset) {
                return (string)$entry['version'] === $this->store->version($dataset);
            }
        }
        return false;
    }
    #[Route('/{dataset}/decision', name: 'ftm_review_decide', methods: ['POST'])]
    public function decide(string $dataset, Request $request): Response
    {
        $this->authorize($dataset);
        $sourceId = $request->request->getString('source');
        $targetId = $request->request->getString('target');
        if (!$this->isCsrfTokenValid($this->store->pair($dataset, $sourceId, $targetId), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        $decision = Decision::tryFrom($request->request->getString('decision'));
        if ($decision === null) {
            return new Response('Invalid decision', 400);
        }
        try {
            $catalog = $this->provider->catalog();
            if (!$this->current($dataset, $catalog) || $request->request->getString('version') !== $this->store->version($dataset)) {
                throw new ReviewConflict('The source or index generation changed. Reload before reviewing.');
            }
            $source = $this->store->source($dataset, $sourceId);
            $target = $this->store->source($dataset, $targetId);
            if ($source === null || $target === null || $sourceId === $targetId) {
                return new Response('Unknown or identical entities', 400);
            }
            $result = $this->provider->match($dataset, $this->model->fromArray($source['entity']));
            $found = false;
            foreach ($result->candidates as $candidate) {
                if ($candidate->entity->id === $targetId) {
                    $found = true;
                }
            }
            if (!$found) {
                throw new ReviewConflict('Candidate list changed. Reload before deciding.');
            }
            $this->store->record($dataset, $sourceId, $targetId, $decision, $this->getUser()->getUserIdentifier(), $request->request->getString('reason'), $request->request->getInt('revision'), [
                'contractVersion' => 1,'provider' => $this->provider->name(),'catalog' => $catalog,'source' => $source,'target' => $target,'matching' => $result->raw,
            ]);
        } catch (ReviewConflict $e) {
            return new Response($e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return new Response($e->getMessage(), 400);
        } catch (ProviderException $e) {
            return new Response('Provider unavailable; no decision saved', 503);
        }
        $this->addFlash('success', 'Decision recorded. Original entities and newspaper claims are unchanged.');
        return $this->redirectToRoute('ftm_review', ['dataset' => $dataset,'id' => $sourceId], 303);
    }
    #[Route('/{dataset}/history/{source}/{target}', name: 'ftm_review_history', methods: ['GET'])]
    public function history(string $dataset, string $source, string $target): JsonResponse
    {
        $this->authorize($dataset);
        $rows = $this->store->history($dataset, $source, $target);
        foreach ($rows as &$row) {
            $row['snapshot'] = json_decode($row['snapshot'],true,flags:JSON_THROW_ON_ERROR);
        }
        return $this->json(['decisions' => array_map(static fn (array $row): array => [
            'id' => $row['id'], 'dataset' => $row['dataset'], 'sourceId' => $row['source_id'],
            'targetId' => $row['target_id'], 'decision' => $row['decision'], 'reviewer' => $row['actor'],
            'createdAt' => $row['created_at'], 'revision' => (int) $row['revision'],
            'reason' => $row['reason'], 'snapshot' => $row['snapshot'],
        ], $rows)]);
    }
}
