<?php declare(strict_types=1);
/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2022 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

namespace Elabftw\Models;

use Elabftw\Elabftw\Db;
use Elabftw\Elabftw\TagParam;
use Elabftw\Enums\Action;
use Elabftw\Exceptions\IllegalActionException;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Interfaces\RestInterface;
use Elabftw\Services\Filter;
use Elabftw\Traits\SetIdTrait;
use PDO;
use Symfony\Component\HttpFoundation\Request;

/**
 * All about the tag but seen from a team perspective, not an entity
 */
class TeamTags implements RestInterface
{
    use SetIdTrait;

    protected Db $Db;

    public function __construct(public Users $Users, ?int $id = null)
    {
        $this->Db = Db::getConnection();
        $this->setId($id);
    }

    public function getPage(): string
    {
        return 'api/v2/tags/';
    }

    public function postAction(Action $action, array $reqBody): int
    {
        return 0;
    }

    public function readOne(): array
    {
        $sql = 'SELECT tag, id
            FROM tags
            WHERE team = :team
            AND id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Users->userData['team'], PDO::PARAM_INT);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $this->Db->fetch($req);
    }

    /**
     * Read all the tags from team
     */
    public function readAll(): array
    {
        // TODO move this out of here
        $Request = Request::createFromGlobals();
        $query = Filter::sanitize((string) $Request->query->get('q'));
        $sql = 'SELECT tag, id FROM tags WHERE team = :team AND tags.tag LIKE :query ORDER BY tag ASC';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Users->userData['team'], PDO::PARAM_INT);
        $req->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function patch(Action $action, array $params): array
    {
        if ($this->Users->userData['is_admin'] !== 1) {
            throw new IllegalActionException('Only an admin can do this!');
        }
        return match ($action) {
            Action::Deduplicate => $this->deduplicate(),
            Action::UpdateTag => $this->updateTag(new TagParam($params['tag'])),
            default => throw new ImproperActionException('Invalid action for tags.'),
        };
    }

    /**
     * Destroy a tag completely. Unreference it from everywhere and then delete it
     */
    public function destroy(): bool
    {
        if ($this->Users->userData['is_admin'] !== 1) {
            throw new IllegalActionException('Only an admin can delete a tag!');
        }
        // first unreference the tag
        $sql = 'DELETE FROM tags2entity WHERE tag_id = :tag_id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
        $this->Db->execute($req);

        // now delete it from the tags table
        $sql = 'DELETE FROM tags WHERE id = :tag_id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':tag_id', $this->id, PDO::PARAM_INT);
        return $this->Db->execute($req);
    }

    /**
     * If we have the same tag (after correcting a typo),
     * remove the tags that are the same and reference only one
     */
    private function deduplicate(): array
    {
        // first get the ids of all the tags that are duplicated in the team
        $sql = 'SELECT GROUP_CONCAT(id) AS id_list FROM tags WHERE tag in (
            SELECT tag FROM tags WHERE team = :team GROUP BY tag HAVING COUNT(*) > 1
        ) GROUP BY tag;';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->Users->userData['team'], PDO::PARAM_INT);
        $this->Db->execute($req);

        $idsToDelete = $req->fetchAll();
        // loop on each tag that needs to be deduplicated and do the work
        foreach ($idsToDelete as $idsList) {
            $this->deduplicateFromIdsList($idsList['id_list']);
        }

        return $this->readAll();
    }

    /**
     * Take a list of tags id and deduplicate them
     * Update the references and delete the tags from the tags table
     *
     * @param string $idsList example: 23,42,1337
     */
    private function deduplicateFromIdsList(string $idsList): void
    {
        // convert the string list into an array
        $idsArr = explode(',', $idsList);
        // pop one out and keep this one
        $idToKeep = array_pop($idsArr);

        // now update the references with the id that we keep
        foreach ($idsArr as $id) {
            $sql = 'UPDATE tags2entity SET tag_id = :target_tag_id WHERE tag_id = :tag_id';
            $req = $this->Db->prepare($sql);
            $req->bindParam(':target_tag_id', $idToKeep, PDO::PARAM_INT);
            $req->bindParam(':tag_id', $id, PDO::PARAM_INT);
            $this->Db->execute($req);

            // and delete that id from the tags table
            $sql = 'DELETE FROM tags WHERE id = :id';
            $req = $this->Db->prepare($sql);
            $req->bindParam(':id', $id, PDO::PARAM_INT);
            $this->Db->execute($req);
        }
    }

    private function updateTag(TagParam $params): array
    {
        // use the team in the sql query to prevent one admin from editing tags from another team
        $sql = 'UPDATE tags SET tag = :tag WHERE id = :id AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $req->bindValue(':tag', $params->getContent(), PDO::PARAM_STR);
        $req->bindParam(':team', $this->Users->userData['team'], PDO::PARAM_INT);

        $this->Db->execute($req);
        return $this->readAll();
    }
}
